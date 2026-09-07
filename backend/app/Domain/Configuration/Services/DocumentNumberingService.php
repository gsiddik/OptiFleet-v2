<?php

namespace App\Domain\Configuration\Services;

use App\Domain\Configuration\Models\ConfigurationVersion;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Invoice\Services\NumberSequenceService;
use App\Domain\Organization\Models\Branch;
use App\Domain\Organization\Models\Warehouse;
use App\Domain\Organization\Models\Workshop;
use Illuminate\Support\Facades\DB;

/**
 * Section 3-6: the single place a configurable document number is
 * generated. Must be called inside the caller's own DB transaction (same
 * contract as NumberSequenceService::next()) so the row lock it takes is
 * held for the duration of the surrounding write. Never overwrites a
 * historical document_number — callers persist the returned number and
 * configuration_version_id once, at creation time, and never touch them
 * again when the numbering configuration is later republished.
 */
class DocumentNumberingService
{
    public function __construct(
        private readonly EffectiveConfigurationResolver $resolver,
        private readonly NumberSequenceService $sequences,
        private readonly NumberingFormatValidator $validator,
    ) {}

    /**
     * @return array{document_number:string, configuration_version_id:string}
     */
    public function generate(string $documentType, string $tenantId, ?string $branchId = null, ?string $workshopId = null, ?string $warehouseId = null): array
    {
        $version = $this->resolver->resolve('NUMBERING', $documentType, $tenantId, $branchId, $workshopId, $warehouseId);
        if (! $version) {
            throw new NumberingException("No published numbering configuration found for document type {$documentType}.");
        }

        $payload = $version->payload;
        $set = $version->configurationSet;
        $scopeResourceId = match ($set->scope_type) {
            'BRANCH' => $branchId,
            'WORKSHOP' => $workshopId,
            'WAREHOUSE' => $warehouseId,
            default => $tenantId,
        };

        // The counter is keyed by the stable configuration SET (not this
        // particular version) so republishing a format tweak continues the
        // same sequence line instead of colliding back at 1 — only an
        // actual reset_rule period change, or a genuinely new set/scope
        // (no prior history), starts a fresh count.
        $periodBucket = $this->periodBucket($payload['reset_rule'] ?? 'NEVER');
        $sequenceKey = "docnum:{$version->configuration_set_id}:{$scopeResourceId}:{$periodBucket}";
        $startAt = (int) ($payload['sequence_start'] ?? 1);

        $seq = $this->sequences->next($sequenceKey, 0, $startAt);

        $number = $this->format($payload, $documentType, $tenantId, $branchId, $workshopId, $warehouseId, $seq);

        return ['document_number' => $number, 'configuration_version_id' => $version->id];
    }

    /** Section 9: preview without mutating any sequence counter. */
    public function preview(array $payload, string $documentType, string $tenantId, ?string $branchId = null, ?string $workshopId = null, ?string $warehouseId = null, int $sampleSequence = 1): string
    {
        $this->validator->validate($payload);
        $startAt = (int) ($payload['sequence_start'] ?? 1);

        return $this->format($payload, $documentType, $tenantId, $branchId, $workshopId, $warehouseId, max($sampleSequence, $startAt));
    }

    private function format(array $payload, string $documentType, string $tenantId, ?string $branchId, ?string $workshopId, ?string $warehouseId, int $seq): string
    {
        $now = now();
        $padding = (int) ($payload['sequence_padding'] ?? 6);

        $tokens = [
            'DOC' => $payload['doc_code'] ?? strtoupper($documentType),
            'TENANT' => Tenant::query()->find($tenantId)?->code ?? $tenantId,
            'BRANCH' => $branchId ? (Branch::query()->find($branchId)?->code ?? $branchId) : '',
            'WORKSHOP' => $workshopId ? (Workshop::query()->find($workshopId)?->code ?? $workshopId) : '',
            'WAREHOUSE' => $warehouseId ? (Warehouse::query()->find($warehouseId)?->code ?? $warehouseId) : '',
            'YYYY' => $now->format('Y'),
            'YY' => $now->format('y'),
            'MM' => $now->format('m'),
            'DD' => $now->format('d'),
        ];

        $result = preg_replace_callback('/\{([A-Z]+)(:(\d+))?\}/', function ($m) use ($tokens, $seq, $padding) {
            if ($m[1] === 'SEQ') {
                $pad = isset($m[3]) ? (int) $m[3] : $padding;

                return str_pad((string) $seq, $pad, '0', STR_PAD_LEFT);
            }

            return $tokens[$m[1]] ?? '';
        }, $payload['format']);

        // Collapse doubled separators left by an empty BRANCH/WORKSHOP/WAREHOUSE token (e.g. unscoped tenant).
        return preg_replace('#/{2,}#', '/', trim($result, '/'));
    }

    private function periodBucket(string $resetRule): string
    {
        $now = now();

        return match ($resetRule) {
            'YEARLY' => $now->format('Y'),
            'MONTHLY' => $now->format('Ym'),
            'DAILY' => $now->format('Ymd'),
            default => 'ALL',
        };
    }
}
