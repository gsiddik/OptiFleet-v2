# Vehicle availability for a transport system (wave 3)

OptiDeli (or another TMS) learns about vehicle availability in two ways. OptiFleet keeps no consumer secret for the first.

## Event

`optifleet.vehicle.availability_changed` is written to the integration outbox, in the same transaction as the change, whenever
`vehicles.operational_status` really changes (AVAILABLE / IN_USE / ON_HOLD): workshop start, release after a work order, breakdown
reported or resolved. Only tenants linked to OptiNexus get one. `optinexus:relay-events` sends it to OptiNexus, whose event broker
delivers it to subscribers. Every change is its own occurrence (the aggregate id is fresh), so going back and forth is never collapsed.
Envelope `data.payload`: `vehicle_id`, `registration_number`, `operational_status`, `previous_operational_status`, `status`, `changed_at` (UTC).
All writers go through `VehicleAvailabilityService`, never a direct update of `operational_status`.

## Query

`GET /api/v1/integration/availability?vehicle=<uuid>|registration_number=<plate>&from=<iso>&to=<iso>` (`from` defaults to now,
`to` to `from`). Read only, `Cache-Control: no-store`, 120 requests per minute.

```json
{ "data": { "vehicle_id": "uuid", "registration_number": "B 1234 XY", "operational_status": "AVAILABLE", "status": "ACTIVE",
            "window": {"from": "...", "to": "..."}, "available": false, "as_of": "...",
            "blockers": [ {"code": "VEHICLE_ON_HOLD", "status": "IN_MAINTENANCE"},
                          {"code": "SERVICE_SCHEDULED", "work_order": "WO-1", "maintenance_type": "PREVENTIVE", "starts_at": "...", "ends_at": "..."} ] } }
```

`available` is true when the vehicle is operationally AVAILABLE and no open work order (not CANCELLED, CLOSED, COMPLETED) with a
target window overlaps `[from, to]`. Unknown vehicle for the tenant: 404 `VEHICLE_NOT_FOUND`. Invalid input: 422.

Authentication is the accounting feed's machine credential model with its own scope: `Authorization: Bearer ofaf_...`, bound to
ONE tenant, scope `fleet.availability.read` (a feed credential is refused here with `FEED_SCOPE_MISSING`, and the other way round).
Issue with `php artisan accounting-feed:issue-credential <tenant> --availability` (consumer `optideli`, label OptiDeli); rotate and
revoke with the existing `accounting-feed:rotate-credential` / `revoke-credential` (a rotation keeps the scope). While OptiNexus pairing
is enforced the tenant must be linked, ACTIVE with a live subscription entitling OptiFleet AND the consumer application
(`OPTINEXUS_AVAILABILITY_CONSUMER_APPLICATION`, default `optideli`); no connector capability is required unless
`OPTINEXUS_AVAILABILITY_CONSUMER_CAPABILITIES` lists some. Refusal codes are the feed's (`FEED_*`).
