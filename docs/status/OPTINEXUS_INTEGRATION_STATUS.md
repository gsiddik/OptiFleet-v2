# OptiNexus Integration — Status

Branch `claude/project-thread-bc4ya2`. Continuation checkpoint between sessions; the repository is the source of truth.
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

## Operating notes

- Register OptiFleet as an OIDC client in OptiNexus (redirect URI = `…/api/v1/auth/sso/callback`, post-logout redirect =
  `<frontend>/login`) and create a gateway service account bound to the OptiFleet application; see
  `OptiNexus/docs/integration/INTEGRATION_GUIDE.md`.
- Link each tenant: set `tenants.optinexus_tenant_id` to the OptiNexus tenant id. Grant `telematics_link.*` to the roles
  that may view / calibrate.

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
| Tests use FerretDB (Mongo wire protocol on Postgres) as the Mongo server | environment caveat |

## Open

- Visual QA of the new screens and an end-to-end run against a real OptiNexus + OptiRadar.
- Re-run the full regression on a CI image with GD JPEG support and a real MongoDB (the 26 failures above are environment-bound).
