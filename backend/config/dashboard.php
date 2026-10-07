<?php

return [
    /*
     * The currency every dashboard money total is expressed in. Records in another currency are
     * never summed into it (no conversion) — they are reported as a limitation instead.
     */
    'base_currency' => env('DASHBOARD_BASE_CURRENCY', 'IDR'),
];
