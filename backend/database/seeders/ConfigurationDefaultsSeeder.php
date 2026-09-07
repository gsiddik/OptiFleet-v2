<?php

namespace Database\Seeders;

use App\Domain\Configuration\Services\ConfigurationService;
use Illuminate\Database\Seeder;

/**
 * Phase 5 Section 52: platform-level default configurations every tenant
 * falls back to until they publish their own override. The numbering
 * defaults reproduce the exact literal formats the old hardcoded
 * generators produced (Section 47: backward compatibility) — new
 * documents created after this seeder runs get identical-looking numbers
 * to before, just generated through the configurable engine.
 */
class ConfigurationDefaultsSeeder extends Seeder
{
    public function run(): void
    {
        $service = app(ConfigurationService::class);

        $numbering = [
            'work_order' => ['format' => 'WO/OPTIFLEET/{YYYY}/{SEQ:6}', 'doc_code' => 'WO', 'reset_rule' => 'YEARLY'],
            'maintenance_request' => ['format' => 'MR/OPTIFLEET/{YYYY}/{SEQ:6}', 'doc_code' => 'MR', 'reset_rule' => 'YEARLY'],
            'vehicle_transfer' => ['format' => 'VT/{YYYY}/{SEQ:6}', 'doc_code' => 'VT', 'reset_rule' => 'YEARLY'],
            'stock_transfer' => ['format' => 'TRF/{YYYY}/{SEQ:6}', 'doc_code' => 'TRF', 'reset_rule' => 'YEARLY'],
            'purchase_request' => ['format' => 'PR/{YYYY}/{SEQ:6}', 'doc_code' => 'PR', 'reset_rule' => 'YEARLY'],
            'rfq' => ['format' => 'RFQ/{YYYY}/{SEQ:6}', 'doc_code' => 'RFQ', 'reset_rule' => 'YEARLY'],
            'purchase_order' => ['format' => 'PO/{YYYY}/{SEQ:6}', 'doc_code' => 'PO', 'reset_rule' => 'YEARLY'],
            'goods_receipt' => ['format' => 'GR/{YYYY}/{SEQ:6}', 'doc_code' => 'GR', 'reset_rule' => 'YEARLY'],
            'warranty_claim' => ['format' => 'WC/{YYYY}/{SEQ:6}', 'doc_code' => 'WC', 'reset_rule' => 'YEARLY'],
        ];

        foreach ($numbering as $code => $payload) {
            $this->seedPlatformDefault($service, 'NUMBERING', $code, ucwords(str_replace('_', ' ', $code)).' Numbering', $payload);
        }
    }

    private function seedPlatformDefault(ConfigurationService $service, string $type, string $code, string $name, array $payload): void
    {
        $set = $service->findOrCreateSet(null, $type, $code, 'TENANT', null, $name, true);
        if ($set->publishedVersion()) {
            return; // already seeded and published — never overwrite published history.
        }
        $service->publish($service->createDraft($set, $payload, null, 'Initial platform default'), null);
    }
}
