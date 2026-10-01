<?php

return [
    /*
    | Vendor invoices (recorded at Goods Receipt) are DUE_SOON when unpaid and their due date is
    | within this many calendar days from today (inclusive); before that they are NEW, after the
    | due date LATE. Single source for the threshold — the frontend only displays the status.
    */
    'invoice_due_soon_days' => (int) env('PROCUREMENT_INVOICE_DUE_SOON_DAYS', 7),
];
