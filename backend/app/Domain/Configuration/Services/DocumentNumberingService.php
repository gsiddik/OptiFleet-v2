<?php

namespace App\Domain\Configuration\Services;

use App\Domain\Configuration\Models\ConfigurationVersion;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Invoice\Services\NumberSequenceService;
use App\Domain\Organization\Models\Branch;
use App\Domain\Organization\Models\Warehouse;
use App\Domain\Organization\Models\Workshop;
use Carbon\CarbonInterface;
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
     * `$context` supplies caller-owned tokens (e.g. ITEMTYPE / CG for product_sku).
     * When given, the counter is additionally partitioned by the rendered prefix
     * (the number with its sequence masked), so every distinct prefix — e.g.
     * SPR-BRK vs CON-LUB — runs its own line and two prefixes can never collide,
     * whatever format a tenant later publishes. Without `$context` the sequence
     * key is exactly what it always was (existing document types are unchanged).
     *
     * @param  array<string, string>  $context
     * @return array{document_number:string, configuration_version_id:string}
     */
    public function generate(string $documentType, string $tenantId, ?string $branchId = null, ?string $workshopId = null, ?string $warehouseId = null, array $context = []): array
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
        if ($context !== []) {
            $sequenceKey .= ':'.$this->format($payload, $documentType, $tenantId, $branchId, $workshopId, $warehouseId, null, $context);
        }
        $startAt = (int) ($payload['sequence_start'] ?? 1);

        $seq = $this->sequences->next($sequenceKey, 0, $startAt);

        $number = $this->format($payload, $documentType, $tenantId, $branchId, $workshopId, $warehouseId, $seq, $context);

        return ['document_number' => $number, 'configuration_version_id' => $version->id];
    }

    /**
     * Section 9: preview without mutating any sequence counter. `$at` is the sample date (now by
     * default). A BRANCH / WORKSHOP / WAREHOUSE token without an Initial and without a given
     * branch / workshop / warehouse shows a sample code of the tenant (its first one), because a
     * real document always has one.
     */
    public function preview(array $payload, string $documentType, string $tenantId, ?string $branchId = null, ?string $workshopId = null, ?string $warehouseId = null, int $sampleSequence = 1, ?CarbonInterface $at = null): string
    {
        $this->validator->validate($payload);
        $startAt = (int) ($payload['sequence_start'] ?? 1);
        $samples = [
            'BRANCH' => Branch::query()->withoutGlobalScopes()->where('tenant_id', $tenantId)->orderBy('code')->value('code') ?? 'BRANCH',
            'WORKSHOP' => Workshop::query()->withoutGlobalScopes()->where('tenant_id', $tenantId)->orderBy('code')->value('code') ?? 'WORKSHOP',
            'WAREHOUSE' => Warehouse::query()->withoutGlobalScopes()->where('tenant_id', $tenantId)->orderBy('code')->value('code') ?? 'WAREHOUSE',
            'ITEMTYPE' => 'SPR', 'CG' => 'BRK',
        ];

        return $this->format($payload, $documentType, $tenantId, $branchId, $workshopId, $warehouseId, max($sampleSequence, $startAt), [], $at, $samples);
    }

    /** `$seq` null renders the sequence as '#' (partition key for context-token formats). */
    private function format(array $payload, string $documentType, string $tenantId, ?string $branchId, ?string $workshopId, ?string $warehouseId, ?int $seq, array $context = [], ?CarbonInterface $at = null, array $samples = []): string
    {
        $now = $at ?? now();
        $padding = (int) ($payload['sequence_padding'] ?? 6);
        // Owner decision: an Initial filled in the configuration overrides the entity's own code
        // for every document of that configuration; left empty, each document keeps the code of
        // its own tenant / branch / workshop / warehouse (the behaviour before Initials existed).
        $initial = fn (string $key) => is_string($payload[$key] ?? null) && trim($payload[$key]) !== '' ? trim($payload[$key]) : null;
        $locale = $now->copy()->locale(app()->getLocale());

        $tokens = [
            'DOC' => $payload['doc_code'] ?? strtoupper($documentType),
            'TENANT' => $initial('tenant_initial') ?? Tenant::query()->find($tenantId)?->code ?? $tenantId,
            'BRANCH' => $initial('branch_initial') ?? ($branchId ? (Branch::query()->find($branchId)?->code ?? $branchId) : ($samples['BRANCH'] ?? '')),
            'WORKSHOP' => $initial('workshop_initial') ?? ($workshopId ? (Workshop::query()->find($workshopId)?->code ?? $workshopId) : ($samples['WORKSHOP'] ?? '')),
            'WAREHOUSE' => $initial('warehouse_initial') ?? ($warehouseId ? (Warehouse::query()->find($warehouseId)?->code ?? $warehouseId) : ($samples['WAREHOUSE'] ?? '')),
            'YYYY' => $now->format('Y'),
            'YY' => $now->format('y'),
            'MMMM' => mb_strtoupper($locale->isoFormat('MMMM')),
            'MMM' => mb_strtoupper(rtrim($locale->isoFormat('MMM'), '.')),
            'MM' => $now->format('m'),
            'DD' => $now->format('d'),
            'ITEMTYPE' => $context['ITEMTYPE'] ?? ($samples['ITEMTYPE'] ?? ''),
            'CG' => $context['CG'] ?? ($samples['CG'] ?? ''),
        ];

        $result = preg_replace_callback('/\{([A-Z]+)(:(\d+))?\}/', function ($m) use ($tokens, $seq, $padding) {
            if ($m[1] === 'SEQ') {
                if ($seq === null) {
                    return '#';
                }
                $pad = isset($m[3]) ? (int) $m[3] : $padding;

                return str_pad((string) $seq, $pad, '0', STR_PAD_LEFT);
            }

            return $tokens[$m[1]] ?? '';
        }, $payload['format']);

        // Collapse doubled separators left by an empty BRANCH/WORKSHOP/WAREHOUSE token (e.g. unscoped tenant).
        $result = preg_replace('#/{2,}#', '/', trim($result, '/'));

        // Context formats (product_sku) may legitimately leave a token empty — e.g. an
        // unclassified Tool has no Component Group: "TOL-{CG}-000001" -> "TOL-000001".
        if ($context !== []) {
            $result = trim(preg_replace('/([-_.\/])\1+/', '$1', $result), '-_./');
        }

        return $result;
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
