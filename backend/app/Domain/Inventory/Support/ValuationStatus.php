<?php

namespace App\Domain\Inventory\Support;

/**
 * Valuation status of inventory (see docs/status/VALUATION_RECONCILIATION_STATUS.md). A status is a fact about the
 * SOURCE of a value, never derived from the number alone: a unit cost of 0 proves neither "free" nor "not valued".
 *
 *  VERIFIED       a positive cost with a traceable source (e.g. the PO unit price of a posted Goods Receipt, or a review);
 *  VERIFIED_ZERO  a zero value that is legitimate — documented basis and evidence recorded by a review;
 *  NOT_VALUED     evidence that valuation was not performed (used stock, or a review stating it);
 *  UNVERIFIED     status not verified — "Status valuasi belum terverifikasi" (legacy zero cost, free goods without a
 *                 basis, opening balances, anything whose source was not checked);
 *  MIXED          a balance combining sources of different status: never presented as verified.
 * Movements carry the status of what they brought in; balances combine them; costs are never altered by a status.
 */
final class ValuationStatus
{
    public const VERIFIED = 'VERIFIED';

    public const VERIFIED_ZERO = 'VERIFIED_ZERO';

    public const NOT_VALUED = 'NOT_VALUED';

    public const UNVERIFIED = 'UNVERIFIED';

    public const MIXED = 'MIXED';

    public const ALL = [self::VERIFIED, self::VERIFIED_ZERO, self::NOT_VALUED, self::UNVERIFIED, self::MIXED];

    /** Bases a reviewer may cite, per target status. */
    public const REVIEW_BASES = [
        self::VERIFIED => ['SUPPLIER_DOCUMENT', 'PURCHASE_ORDER_PRICE', 'COSTING_REVIEW'],
        self::VERIFIED_ZERO => ['FREE_OF_CHARGE_DOCUMENTED', 'DONATION_DOCUMENTED', 'NO_COST_BASIS_DOCUMENTED'],
        self::NOT_VALUED => ['VALUATION_NOT_PERFORMED'],
        self::UNVERIFIED => ['REVIEW_REVOKED'],
    ];

    private function __construct() {}

    /** Status of a balance after quantity of $incoming status joins an existing quantity of $current status. */
    public static function combine(?string $current, float $currentQuantity, string $incoming): string
    {
        if ($currentQuantity <= 0 || $current === null) {
            return $incoming;
        }
        if ($current === $incoming) {
            return $current;
        }
        $verified = [self::VERIFIED, self::VERIFIED_ZERO];
        if (in_array($current, $verified, true) && in_array($incoming, $verified, true)) {
            return self::VERIFIED;
        }

        return self::MIXED;
    }
}
