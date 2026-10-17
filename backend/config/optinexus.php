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
];
