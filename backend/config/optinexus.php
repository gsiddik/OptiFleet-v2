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

    // API Gateway: platform-level service account (acts per tenant via X-Tenant-Id).
    'gateway' => [
        'client_id' => env('OPTINEXUS_GATEWAY_CLIENT_ID'),
        'client_secret' => env('OPTINEXUS_GATEWAY_CLIENT_SECRET'),
        'timeout_seconds' => (int) env('OPTINEXUS_GATEWAY_TIMEOUT', 20),
        'feed_page_size' => 200,
    ],
];
