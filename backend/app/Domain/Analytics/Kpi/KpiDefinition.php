<?php

namespace App\Domain\Analytics\Kpi;

/**
 * Phase 6 Section 38 — a reusable, documented KPI definition. The
 * `calculator` closure receives (tenantId, fromDate, toDate, dimension,
 * dimensionValue) and returns ['value','numerator','denominator','unit'].
 * This is deliberately NOT a scripting engine (Section 38 explicitly
 * rules that out) — every calculator is a fixed, reviewed PHP closure
 * registered once in KpiCatalog, not user-supplied logic.
 */
class KpiDefinition
{
    /**
     * @param  string[]  $supportedDimensions
     */
    public function __construct(
        public readonly string $code,
        public readonly string $label,
        public readonly string $unit,
        public readonly string $description,
        public readonly string $formula,
        public readonly array $supportedDimensions,
        /**
         * Each KPI filters at most one dimension (its primary collection's
         * own key field) — $dimensionValue is null for the tenant-wide
         * rollup, or an ID to scope to one branch/workshop/etc, per
         * $supportedDimensions.
         *
         * @var callable(string,\Carbon\CarbonImmutable,\Carbon\CarbonImmutable,mixed):array
         */
        public $calculator,
    ) {}

    public function calculate(string $tenantId, \Carbon\CarbonImmutable $from, \Carbon\CarbonImmutable $to, mixed $dimensionValue = null): array
    {
        $result = ($this->calculator)($tenantId, $from, $to, $dimensionValue);

        return array_merge([
            'code' => $this->code,
            'label' => $this->label,
            'unit' => $this->unit,
            'description' => $this->description,
            'formula' => $this->formula,
            'period' => ['from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d')],
        ], $result);
    }

    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'label' => $this->label,
            'unit' => $this->unit,
            'description' => $this->description,
            'formula' => $this->formula,
            'supported_dimensions' => $this->supportedDimensions,
        ];
    }
}
