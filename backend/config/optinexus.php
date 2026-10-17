<?php

return [
    /*
    | OptiNexus platform integration. Off by default: with enabled=false
    | OptiFleet behaves exactly as before (password login only, no sync).
    */
    'enabled' => (bool) env('OPTINEXUS_ENABLED', false),

    // Public base URL of OptiNexus (also its OIDC issuer), no trailing slash.
    'base_url' => rtrim((string) env('OPTINEXUS_BASE_URL', ''), '/'),

    // SSO: OIDC client registered in OptiNexus (POST /api/v1/oidc-clients).
    'sso' => [
        'client_id' => env('OPTINEXUS_SSO_CLIENT_ID'),
        'client_secret' => env('OPTINEXUS_SSO_CLIENT_SECRET'),
        // Must be registered exactly as a redirect URI on the OIDC client.
        'redirect_uri' => env('OPTINEXUS_SSO_REDIRECT_URI'),
        // Where the browser lands after the callback (the SPA).
        'frontend_url' => rtrim((string) env('OPTINEXUS_SSO_FRONTEND_URL', env('APP_URL', '')), '/'),
        'state_ttl_seconds' => 600,
        'ticket_ttl_seconds' => 60,
    ],

    // Integration events (invoice and memo events from the outbox) reported to OptiNexus POST /api/v1/events.
    // Needs the gateway service account below, bound to the OptiFleet application with the event.write scope.
    'events' => [
        'enabled' => (bool) env('OPTINEXUS_EVENTS_ENABLED', false),
        'batch_size' => 100,
        // After this many failed attempts an event is parked as FAILED (optinexus:relay-events --retry-failed puts it back).
        'max_attempts' => 20,
    ],

    // API Gateway: platform-level service account (acts per tenant via X-Tenant-Id).
    'gateway' => [
        'client_id' => env('OPTINEXUS_GATEWAY_CLIENT_ID'),
        'client_secret' => env('OPTINEXUS_GATEWAY_CLIENT_SECRET'),
        'timeout_seconds' => (int) env('OPTINEXUS_GATEWAY_TIMEOUT', 20),
        'feed_page_size' => 200,
    ],

    /*
    | Central governance (audit A02, R05). OptiNexus is the only authority for tenants in CENTRAL mode: what a user may do
    | (POST /authorization/effective-permissions) and which modules the tenant holds (commercial context) are read from it,
    | cached for at most `ttl_seconds` (never above 300) and never used after that. If OptiNexus cannot answer, access is
    | denied (HTTP 503 GOVERNANCE_UNAVAILABLE); there is no local fallback. Tenants stay LOCAL (legacy) until an operator
    | runs `optinexus:governance:enable`.
    */
    'governance' => [
        'ttl_seconds' => max(1, min(300, (int) env('OPTINEXUS_GOVERNANCE_TTL', 300))),
        // The product's own application code in OptiNexus; permission keys are "<code>.<local permission name>".
        'application_code' => env('OPTINEXUS_APPLICATION_CODE', 'optifleet'),
        'timeout_seconds' => max(1, (int) env('OPTINEXUS_GOVERNANCE_TIMEOUT', 5)),
        'connect_timeout_seconds' => max(1, (int) env('OPTINEXUS_GOVERNANCE_CONNECT_TIMEOUT', 3)),
        // One short wait is honoured after HTTP 429 (Retry-After); anything longer fails closed immediately.
        'max_retry_after_seconds' => 2,
        // Most members asked per approval step when a permission has to be matched against OptiNexus (no bulk "who holds it").
        'approver_scan_limit' => 200,
    ],

    /*
    | Accounting feed access (audit A19). The feed is served to OptiEntry only for the pair of products OptiNexus says the
    | tenant has: the credential is bound to the tenant's OptiNexus identity at issue time, and on every request (answer cached
    | for at most `governance.ttl_seconds`) OptiNexus must report the tenant ACTIVE with a live subscription that entitles BOTH
    | this application and the consumer application, plus the consumer's connector capabilities. Anything else is denied; an
    | unreachable OptiNexus answers 503 (the consumer keeps its cursor). Fleet itself keeps recording and projecting facts.
    |
    |   pairing: `auto` enforces it whenever OPTINEXUS_ENABLED is true (legacy installs without OptiNexus keep the old
    |            credential-only behaviour); `required` enforces it always (fails closed without OptiNexus).
    */
    'feed' => [
        'pairing' => in_array(env('OPTINEXUS_FEED_PAIRING', 'auto'), ['auto', 'required'], true) ? env('OPTINEXUS_FEED_PAIRING', 'auto') : 'required',
        'consumer_application' => env('OPTINEXUS_FEED_CONSUMER_APPLICATION', 'optiaccounting'),
        // OptiEntry projects its module/feature codes 1:1 as OptiNexus capability codes (docs/architecture/OPTINEXUS_ADAPTER.md there).
        'consumer_capabilities' => array_values(array_filter(array_map('trim', explode(',', (string) env('OPTINEXUS_FEED_CONSUMER_CAPABILITIES', 'ACCOUNTING_INTEGRATION,OPTIFLEET_CONNECTOR'))))),
        // Optional capability of this application that sells the feed separately; empty = the application subscription is enough.
        'own_capability' => env('OPTINEXUS_FEED_OWN_CAPABILITY'),
        // Subscription statuses OptiNexus itself treats as commercially live.
        'live_subscription_statuses' => ['ACTIVE', 'TRIAL', 'GRACE_PERIOD', 'PAST_DUE'],
        // A feed fact missing this long after its outbox row is reported by accounting-feed:monitor.
        'missed_after_minutes' => (int) env('ACCOUNTING_FEED_MISSED_AFTER_MINUTES', 2),
    ],

    /*
    | Telematics odometer feed (audit A10), read through the OptiNexus API Gateway. A tenant is synced only when ALL hold:
    | OptiNexus is enabled, the tenant is ACTIVE and linked, the tenant switched the feed on (tenants.telematics_activated_at), and
    | OptiNexus says the tenant has a live subscription that includes the telematics application (commercial context, cached at most
    | `governance.ttl_seconds`). Anything else is skipped and the reason is stored and shown; nothing is requested for such a tenant.
    |
    | A reading is judged against the last one accepted from the same device and kind. It is held for a person (quarantined, never
    | applied, the odometer is never lowered by it) when it is older than the device clock allows, lower than the last value, further
    | away than a vehicle could have driven, or comes from a different device than the vehicle had before. The limits are plain
    | physical bounds, not business rules; they only decide what a person must look at.
    */
    'telematics' => [
        'application_code' => env('OPTINEXUS_TELEMATICS_APPLICATION', 'optiradar'),
        // A reading measured later than this many seconds after it was received is a device clock fault (quarantined).
        'future_tolerance_seconds' => (int) env('OPTINEXUS_TELEMATICS_FUTURE_TOLERANCE', 300),
        // A device counter may fall by this much (km) before it counts as a decrease (rounding / display jitter).
        'decrease_tolerance_km' => env('OPTINEXUS_TELEMATICS_DECREASE_TOLERANCE_KM', '1.00'),
        // Largest distance (km) a vehicle may cover, on top of what its speed explains, between two readings of one device.
        'jump_slack_km' => env('OPTINEXUS_TELEMATICS_JUMP_SLACK_KM', '50'),
        'jump_max_average_kmh' => env('OPTINEXUS_TELEMATICS_JUMP_MAX_KMH', '160'),
        // Status labels (they never drop or hide data): no successful sync for this long, or no new reading from a device for this long.
        'sync_stale_minutes' => max(1, (int) env('OPTINEXUS_TELEMATICS_SYNC_STALE_MINUTES', 15)),
        'reading_stale_hours' => max(1, (int) env('OPTINEXUS_TELEMATICS_READING_STALE_HOURS', 24)),
        // Distinct problems kept per run for the status screen.
        'rejected_sample' => 5,
    ],
];
