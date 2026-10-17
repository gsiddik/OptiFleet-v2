# Odometer and telematics (audit A10)

Status: backend DONE and tested; Fleet screens for the new states and the radar side are DEFERRED (owner scope update). See
`docs/status/ECOSYSTEM_AUDIT_STATUS.md` for exactly what exists and what is missing.

## Principle

The odometer works fully by hand. Telematics (OptiRadar through the OptiNexus API Gateway) is an optional extra source: nothing here needs it,
nothing is requested for a tenant that is not eligible, and nothing is invented when it is silent (no zero-fill, no guessed values).

## One ledger, one writer

`vehicle_odometer_readings` is the ledger for every source (`manual`, `optinexus`, `inspection`, `work_order`, `tire_operation`,
`vehicle_release`). `Vehicle\Services\OdometerService` is the only writer of `vehicles.current_odometer`; it locks the vehicle row (lock order:
vehicle, then telematics link). The old direct writes in inspection, work order, tire operation and vehicle release now go through it.
`current_odometer` only ever rises through a source. Lowering is a **correction**: a person, a reason of at least 10 characters, an
`odometer_corrected` audit row, disposition `correction`. Telematics never lowers it.

| Field | Meaning |
|---|---|
| `measured_at` (column `recorded_at`, alias kept) | when the value was true: device clock or the person's reading |
| `received_at` | when OptiFleet stored it; never used as the measurement time |
| `source` / `origin` | who produced it (`optinexus`, `manual`, ...) / the gateway's own source name (`optiradar`) |
| `unit` | always `km` (CHECK); values are decimal strings, `NUMERIC(12,2)`, brick/math in PHP |
| `quality` | derived: `OK`, `LATE`, `UNCALIBRATED`, `SUSPECT` |
| `disposition` | `applied`, `stored` (not higher), `held` (GPS distance, link not calibrated), `late`, `quarantined`, `correction` |

Vehicle provenance: `odometer_source`, `odometer_measured_at`, `odometer_reading_id`. NULL on a vehicle recorded before the migration
means "origin unknown" and is shown as unknown, never guessed as manual.

## Eligibility (two keys, both required)

1. OptiNexus says the tenant has a live subscription that includes the application `optiradar` (`commercial-context`, cached <= 300 s).
2. The tenant switched the feed on in Fleet (`tenants.telematics_activated_at`; `PUT /telematics/activation`).

Tenants whose feed had already synced before the migration are backfilled as activated at their first sync. The scheduled
`optinexus:sync` only touches activated tenants; `--tenant` prints why a tenant is skipped.

## Anomalies (quarantine, never auto-apply)

A reading is compared with the last accepted one of the same device and kind. Quarantined readings are stored as evidence, never applied,
and open one review per device (the device's later readings are quarantined too until a person decides).

| Code | Rule (config `optinexus.telematics.*`) | Review |
|---|---|---|
| `COUNTER_DECREASE` | device counter fell by more than `decrease_tolerance_km` (1.00): reset | yes |
| `IMPLAUSIBLE_JUMP` | rise above `jump_slack_km` (50) + elapsed hours x `jump_max_average_kmh` (160) | yes |
| `DEVICE_CHANGED` | a different device than the vehicle had before | yes |
| `FUTURE_TIME` | measured later than received + `future_tolerance_seconds` (300): clock fault | no (does not move the baseline) |

`late` = measured strictly before the link's newest measurement (out-of-order arrival): stored, never applied, not an anomaly.
Review decisions (`POST /telematics-links/{link}/review`, note required, audited as `telematics.review_<decision>`): `dismiss` (ignore the
reading), `accept` (adopt it; lowering needs a real reason), `rebase` (align the device to the odometer recorded here, or to a given real
value; never lower). Calibration is refused while a review is open and cannot carry quarantined readings.
First reading of the first device on a vehicle is applied as before (legacy behaviour); stricter first-contact confirmation is an owner decision.

## Visibility (persisted, no call to OptiNexus to answer it)

`GET /telematics/status`: `state` is one of `disabled`, `not_linked`, `not_activated`, `pending`, `not_subscribed`, `provider_unavailable`,
`error`, `stale`, `partial`, `ok`; plus last attempt, last success, last reading received, last error `{code, message}`, consecutive
failures, last run counts (received/applied/stored/held/late/quarantined/duplicates/rejected + capped sample of rejected items), device counts
(with/without readings, stale after 24 h, under review, needs calibration) and quarantined count of 7 days. Per link: `last_measured_at`,
`last_received_at`, `age_seconds` (null = never reported), `stale`, `status`, `review`. Stale labels never hide data. Error codes:
gateway code, `HTTP_<status>`, `CONNECTION_FAILED`, `GATEWAY_BAD_RESPONSE`, `SYNC_FAILED`. Malformed items are counted and skipped, never zero-filled.

## API (module VEHICLE)

| Route | Permission |
|---|---|
| `GET /telematics/status`, `GET /telematics-links` (`review`, `needs_calibration`) | `telematics_link.view` |
| `PUT /telematics/activation`, `PUT /telematics-links/{id}/calibration`, `POST /telematics-links/{id}/review` | `telematics_link.manage` |
| `GET /vehicles/{id}/odometer`, `GET /vehicles/{id}/odometer-readings` | `vehicle.view` |
| `POST /vehicles/{id}/odometer-readings` (`odometer_km`, `measured_at`, `note`, `idempotency_key`, `correction_reason`) | `vehicle.update` |
| `PUT /vehicles/{id}` with `current_odometer` (+ `odometer_correction_reason`) | `vehicle.update`, same rules |

Errors keep English `code`s (`ODOMETER_INVALID`, `ODOMETER_FUTURE_TIME`, `ODOMETER_BELOW_CURRENT`, `ODOMETER_TIME_CONFLICT`,
`ODOMETER_IDEMPOTENCY_CONFLICT`, `TELEMATICS_REVIEW_PENDING`, ...); messages follow the request language (EN/ID in the i18n catalog).

## Contract facts and gaps for OptiNexus / OptiRadar

- The gateway deduplicates on `(tenant, source, device_ref, recorded_at)`: a newer odometer with an unchanged fix time is dropped (parked
  vehicles produce no new readings). The feed carries no `received_at`, `unit` or quality; Fleet derives them.
- `commercial-context` does not map subscriptions to applications (proposal: `subscriptions[].application_codes`); Fleet infers it.
- Service account scopes for Fleet: `authorization.check`, `commercial.read`, `tenant.delegate`, plus gateway scopes `gateway.fleet.write`,
  `gateway.telematics.read`, `event.write`, bound to `optifleet`.
- Not verified against a real OptiNexus or OptiRadar: every test uses FakeNexus / FakeNexusGateway built from the documented JSON.
