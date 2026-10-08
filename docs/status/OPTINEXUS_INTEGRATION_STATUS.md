# OptiNexus Integration — Status

Branches: `claude/project-thread-bc4ya2` (SSO + odometer gateway, PR #32) and `claude/nexus-lifecycle-events` (stacked on it:
central logout, deactivation, events). `main` is the baseline; new work starts from `main` on a new branch.
Continuation checkpoint between sessions; the repository is the source of truth.
Cross-repo design and decisions: `OptiNexus/docs/integration/ARCHITECTURE.md` (branch of the same name).

## Scope (owner decisions)

- OptiNexus is the identity provider (OpenID Connect) and the API Gateway between platforms.
- Odometer direction is OptiRadar → OptiFleet. Manual odometer entry in OptiFleet is unchanged, so tenants without
  OptiRadar are unaffected; sync only runs for tenants linked to OptiNexus (`tenants.optinexus_tenant_id`).
- GPS distance is not an odometer: it is held until a manual calibration (offset, or actual odometer) is made.
  Telematics only ever raises `current_odometer`.
- SSO signs in existing users only (no automatic account creation). Password login stays available.
- Contract / billing modules are untouched in this phase (move to OptiNexus later).

## Checkpoints

| # | Checkpoint | Status |
|---|---|---|
| 1 | Schema, config, SSO building blocks (JWT RS256 verifier, OIDC client) | DONE |
| 2 | SSO login (redirect, callback, one-time ticket exchange), `optinexus:sync` odometer pull, calibration API | DONE |
| 3 | Frontend: "Sign in with OptiNexus", `/sso/callback`, app switcher, Telematics Links page (calibration), EN/ID strings | DONE |
| 4 | Full backend regression run once at the end of this work | DONE with environment-caused failures (see Validation) |
| 5 | Standalone login proof (works with OptiNexus off, down, or never configured) | DONE (`StandaloneLoginTest`, 6 tests) |
| 6 | Back-channel logout receiver: sessions end, account deactivated on access loss, reactivated only by the next SSO login | DONE (`BackchannelLogoutTest`, 14 tests) |
| 7 | Report integration outbox events to OptiNexus (`optinexus:relay-events`) | DONE (`EventRelayTest`, 10 tests) |

## What was added

Backend (`backend/`): `app/Domain/Integration/Optinexus/*` (verifier, OIDC client, `SsoLoginService`, gateway client,
`VehicleOdometerService`, `OptinexusSyncService`), `Api/Auth/SsoController`, `Api/Tenant/TelematicsLinkController`,
command `optinexus:sync` (scheduled), migrations `2026_10_20_000001/2` (tables, permissions `telematics_link.view|manage`),
`config/optinexus.php`, env keys `OPTINEXUS_*` in `.env.example` (disabled by default).

Routes: `GET /api/v1/auth/sso/{status,redirect,callback}`, `POST /api/v1/auth/sso/exchange`,
`GET /api/v1/app/telematics-links[?needs_calibration=true]`, `PUT /api/v1/app/telematics-links/{link}/calibration` (module `VEHICLE`).

Frontend (`frontend/`): `LoginPage` SSO button (shown when `/auth/sso/status` is enabled; `?sso_error=` is translated),
`SsoCallbackPage`, `AuthContext.completeSsoLogin` and `sso` session (logout also ends the OptiNexus session),
`AppSwitcher` in the tenant header, `TelematicsLinkPage` at `/app/telematics-links`, nav item under Vehicle,
i18n rows in `docs/i18n/17-i18n-additions.csv` (generated resources regenerated).

## Central logout and deactivation (checkpoint 6)

`POST /api/v1/auth/sso/backchannel-logout` (form field `logout_token`, no other credential): the token is an RS256
`logout+jwt` signed by OptiNexus, checked for signature, issuer, audience (`OPTINEXUS_SSO_CLIENT_ID`), age (10 minutes),
type, the logout event, `sub`, `jti` (accepted once) and the absence of `nonce`. A plain logout deletes the user's
Sanctum tokens (all tenants). `access-revoked` (custom event, scope `user` or `tenant`) also marks the account
(`users.optinexus_deactivated_at`, or `tenant_users.optinexus_deactivated_at` for one tenant) and sets it inactive, so
password login answers "This account has been deactivated." The next successful SSO sign-in (`SsoLoginService`) clears
exactly those marks; an account an OptiFleet administrator switched off has no mark and stays off. Users are matched by
`optinexus_subject`, else by e-mail; platform users and unlinked tenants are never touched.

## Events to OptiNexus (checkpoint 7)

`OptinexusEventRelay` reads `integration_outbox_events` of tenants that have `optinexus_tenant_id`, posts them to
`POST /api/v1/events` (scope `event.write`, env `OPTINEXUS_EVENTS_ENABLED`, off by default) with the outbox row id as
`event_id`, key `optifleet.<event_type>`, and the OptiNexus tenant id. PostgreSQL stays the source of truth: a row only
becomes DELIVERED after OptiNexus accepted it. Retries use backoff (2 min doubling to 1 h, 20 attempts); a refusal that
retrying cannot fix parks the row as FAILED (`optinexus:relay-events --retry-failed`). Scheduled every minute.
The six events are registered in OptiNexus by `OptiFleetEventCatalogSeeder`.

## Seeders, roles and permissions (review)

No seeder change was needed in this repository: `PermissionSeeder` already seeds `telematics_link.view|manage`;
migration `2026_10_20_000002` grants them to existing roles that hold `vehicle.view` / `vehicle.update`; the demo and
functional-test Tenant Admin roles are synced with every tenant permission, so they include them. Real tenants build
their roles in the Role Editor (dynamic RBAC), which is why nothing hardcodes a role name. No migration added a
permission for the new endpoint (`backchannel-logout` and the relay have none: the first is authorized by the signed
token, the second is a console command).

## Operating notes

- Register OptiFleet as an OIDC client in OptiNexus (redirect URI = `…/api/v1/auth/sso/callback`, post-logout redirect =
  `<frontend>/login`) and create a gateway service account bound to the OptiFleet application; see
  `OptiNexus/docs/integration/INTEGRATION_GUIDE.md`.
- Link each tenant: set `tenants.optinexus_tenant_id` to the OptiNexus tenant id. Grant `telematics_link.*` to the roles
  that may view / calibrate.
- Central logout: register `<api>/api/v1/auth/sso/backchannel-logout` as the client's back-channel logout URI.
- Events: in OptiNexus run `db:seed --class=OptiFleetEventCatalogSeeder`, give the OptiFleet service account the
  `event.write` scope, then set `OPTINEXUS_EVENTS_ENABLED=true` here.

## Validation (this session, local environment)

| Check | Result |
|---|---|
| `tests/Feature/Optinexus` (SSO 21, odometer sync + calibration 19) | PASS (executed) |
| Frontend `tsc -b`, `npm run build`, `npm run i18n:check` | PASS (executed) |
| Frontend `npm run test:unit` | PASS 56/56 (executed) |
| Frontend `oxlint` | exit 0, no errors; the only warnings in touched files (`AuthContext.tsx`) were already there |
| Backend full regression (`php artisan test`, whole suite) | 1398 passed, 26 failed. 23 failures are `imagejpeg()` undefined (PHP built locally without GD JPEG; image upload tests). 3 failures in `Analytics` (`AnalyticsBackfillAndScopeTest` x2, `CostTireComponentWarrantyAnalyticsTest` x1) fail identically on the base commit `7d9470b`, so they predate this work (FerretDB stand-in / test data). None is related to this change. They must be re-run on CI with GD JPEG and a real MongoDB. |
| Browser (manual) check of the new screens | NOT RUN (no browser session; the API side was exercised below) |
| End-to-end against a live OptiNexus + OptiRadar (Traccar) + this backend (throw-away databases, scripted browser): SSO both ways without a second login prompt, ticket replay refused, odometer from a device (applied), GPS distance (held, then calibrated through the API with an SSO token and applied) | PASS (executed, scripted; not part of the test suite) |
| `tests/Feature/Optinexus` after the lifecycle and event work (SSO, odometer, standalone login, back-channel logout, event relay) | PASS 70/70 (executed) |
| `AuthTest`, `RolePermissionManagementTest`, `LocalePreferenceTest`, `ValidationLocalizationTest` (neighbours of the touched login/token code) | PASS 33/33 (executed) |
| Full backend regression for the lifecycle/event batch | NOT RUN (the change is additive and flag-gated; the full run is kept for the release gate, see the earlier row) |
| Live run, OptiNexus + OptiFleet + OptiRadar fork + OptiRadar-web (throw-away databases, scripted, not in the suite): standalone password login, SSO account creation with `users.defaultDeviceLimit=0`, logout in one app ends the others, force logout of two sessions, suspend deactivates Fleet and Radar accounts, activate + SSO reactivates, hand-disabled Radar account stays disabled, removing one app access ends only that app, Fleet event reaches OptiNexus under its outbox id, unknown event type waits for the catalog | PASS 32/32 checks (executed); Playwright run of the Radar web "Log Out" (redirect to OptiNexus, back to Radar login, Fleet token refused) PASS |
| Tests use FerretDB (Mongo wire protocol on Postgres) as the Mongo server | environment caveat |

## Open

- Merge order: PR #32 (`claude/project-thread-bc4ya2`) first, then the lifecycle PR (stacked on it).
- Not covered: a delivery that keeps failing is not repeated by OptiNexus's reconciler (it only looks at active sessions);
  OptiFleet API tokens have no expiry of their own, they end through these events.
- Visual QA of the new screens and an end-to-end run against a real OptiNexus + OptiRadar.
- Re-run the full regression on a CI image with GD JPEG support and a real MongoDB (the 26 failures above are environment-bound).
