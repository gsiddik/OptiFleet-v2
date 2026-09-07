<?php

namespace Tests\Feature;

use App\Domain\Configuration\Services\ConfigurationService;
use App\Domain\Configuration\Services\DocumentNumberingService;
use App\Domain\Configuration\Services\NumberingException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DocumentNumberingTest extends TestCase
{
    private function publishNumbering(ConfigurationService $service, ?string $tenantId, string $scopeType, ?string $scopeResourceId, array $payload): void
    {
        $set = $service->findOrCreateSet($tenantId, 'NUMBERING', 'work_order', $scopeType, $scopeResourceId, 'Work Order Numbering');
        $service->publish($service->createDraft($set, $payload, null), null);
    }

    public function test_numbering_create_and_publish_generates_configured_format(): void
    {
        $tenant = $this->makeTenant(['code' => 'NUM-'.\Illuminate\Support\Str::random(4)]);
        $configService = app(ConfigurationService::class);
        $this->publishNumbering($configService, $tenant->id, 'TENANT', null, [
            'format' => '{DOC}/{YYYY}/{SEQ:6}', 'doc_code' => 'WO', 'reset_rule' => 'NEVER',
        ]);

        $numbering = app(DocumentNumberingService::class);
        $result = DB::transaction(fn () => $numbering->generate('work_order', $tenant->id));

        $this->assertMatchesRegularExpression('#^WO/\d{4}/000001$#', $result['document_number']);
        $this->assertNotNull($result['configuration_version_id']);
    }

    public function test_numbering_sequence_is_tenant_isolated(): void
    {
        $tenantA = $this->makeTenant(['code' => 'NUMA-'.\Illuminate\Support\Str::random(4)]);
        $tenantB = $this->makeTenant(['code' => 'NUMB-'.\Illuminate\Support\Str::random(4)]);
        $configService = app(ConfigurationService::class);
        $this->publishNumbering($configService, $tenantA->id, 'TENANT', null, ['format' => '{DOC}/{SEQ:4}', 'doc_code' => 'WO']);
        $this->publishNumbering($configService, $tenantB->id, 'TENANT', null, ['format' => '{DOC}/{SEQ:4}', 'doc_code' => 'WO']);

        $numbering = app(DocumentNumberingService::class);
        $a1 = DB::transaction(fn () => $numbering->generate('work_order', $tenantA->id));
        $b1 = DB::transaction(fn () => $numbering->generate('work_order', $tenantB->id));
        $a2 = DB::transaction(fn () => $numbering->generate('work_order', $tenantA->id));

        $this->assertSame('WO/0001', $a1['document_number']);
        $this->assertSame('WO/0001', $b1['document_number']); // independent sequence, not shared with tenant A
        $this->assertSame('WO/0002', $a2['document_number']);
    }

    public function test_numbering_branch_override_uses_independent_sequence(): void
    {
        $tenant = $this->makeTenant(['code' => 'NUM-'.\Illuminate\Support\Str::random(4)]);
        $branch = $this->makeBranch($tenant);
        $configService = app(ConfigurationService::class);
        $this->publishNumbering($configService, $tenant->id, 'TENANT', null, ['format' => 'TEN-{SEQ:4}', 'doc_code' => 'WO']);
        $this->publishNumbering($configService, $tenant->id, 'BRANCH', $branch->id, ['format' => 'BR-{SEQ:4}', 'doc_code' => 'WO']);

        $numbering = app(DocumentNumberingService::class);
        $withBranch = DB::transaction(fn () => $numbering->generate('work_order', $tenant->id, $branch->id));
        $withoutBranch = DB::transaction(fn () => $numbering->generate('work_order', $tenant->id));

        $this->assertSame('BR-0001', $withBranch['document_number']);
        $this->assertSame('TEN-0001', $withoutBranch['document_number']);
    }

    public function test_numbering_workshop_override_falls_back_to_branch_then_tenant(): void
    {
        $tenant = $this->makeTenant(['code' => 'NUM-'.\Illuminate\Support\Str::random(4)]);
        $branch = $this->makeBranch($tenant);
        $workshop = $this->makeWorkshop($tenant, $branch);
        $configService = app(ConfigurationService::class);
        $this->publishNumbering($configService, $tenant->id, 'TENANT', null, ['format' => 'TEN-{SEQ:4}', 'doc_code' => 'WO']);

        $numbering = app(DocumentNumberingService::class);
        $result = DB::transaction(fn () => $numbering->generate('work_order', $tenant->id, $branch->id, $workshop->id));
        $this->assertSame('TEN-0001', $result['document_number']);

        $this->publishNumbering($configService, $tenant->id, 'WORKSHOP', $workshop->id, ['format' => 'WS-{SEQ:4}', 'doc_code' => 'WO']);
        $result = DB::transaction(fn () => $numbering->generate('work_order', $tenant->id, $branch->id, $workshop->id));
        $this->assertSame('WS-0001', $result['document_number']);
    }

    public function test_numbering_reset_yearly(): void
    {
        Carbon::setTestNow('2025-06-15');
        $tenant = $this->makeTenant(['code' => 'NUM-'.\Illuminate\Support\Str::random(4)]);
        $configService = app(ConfigurationService::class);
        $this->publishNumbering($configService, $tenant->id, 'TENANT', null, ['format' => '{YYYY}-{SEQ:3}', 'doc_code' => 'WO', 'reset_rule' => 'YEARLY']);
        $numbering = app(DocumentNumberingService::class);

        $y1 = DB::transaction(fn () => $numbering->generate('work_order', $tenant->id));
        $this->assertSame('2025-001', $y1['document_number']);

        Carbon::setTestNow('2026-01-02');
        $y2 = DB::transaction(fn () => $numbering->generate('work_order', $tenant->id));
        $this->assertSame('2026-001', $y2['document_number']);

        Carbon::setTestNow();
    }

    public function test_numbering_reset_monthly(): void
    {
        Carbon::setTestNow('2025-06-15');
        $tenant = $this->makeTenant(['code' => 'NUM-'.\Illuminate\Support\Str::random(4)]);
        $configService = app(ConfigurationService::class);
        $this->publishNumbering($configService, $tenant->id, 'TENANT', null, ['format' => '{YYYY}{MM}-{SEQ:3}', 'doc_code' => 'WO', 'reset_rule' => 'MONTHLY']);
        $numbering = app(DocumentNumberingService::class);

        $m1 = DB::transaction(fn () => $numbering->generate('work_order', $tenant->id));
        $this->assertSame('202506-001', $m1['document_number']);

        Carbon::setTestNow('2025-07-01');
        $m2 = DB::transaction(fn () => $numbering->generate('work_order', $tenant->id));
        $this->assertSame('202507-001', $m2['document_number']);

        Carbon::setTestNow();
    }

    public function test_numbering_preview_does_not_consume_sequence(): void
    {
        $tenant = $this->makeTenant(['code' => 'NUM-'.\Illuminate\Support\Str::random(4)]);
        $configService = app(ConfigurationService::class);
        $payload = ['format' => 'WO/{SEQ:6}', 'doc_code' => 'WO'];
        $this->publishNumbering($configService, $tenant->id, 'TENANT', null, $payload);
        $numbering = app(DocumentNumberingService::class);

        $preview1 = $numbering->preview($payload, 'work_order', $tenant->id);
        $preview2 = $numbering->preview($payload, 'work_order', $tenant->id);
        $this->assertSame($preview1, $preview2);

        $actual = DB::transaction(fn () => $numbering->generate('work_order', $tenant->id));
        $this->assertSame('WO/000001', $actual['document_number']);
    }

    public function test_numbering_immutability_preserves_historical_format_after_republish(): void
    {
        $tenant = $this->makeTenant(['code' => 'NUM-'.\Illuminate\Support\Str::random(4)]);
        $configService = app(ConfigurationService::class);
        $this->publishNumbering($configService, $tenant->id, 'TENANT', null, ['format' => 'OLD-{SEQ:4}', 'doc_code' => 'WO']);
        $numbering = app(DocumentNumberingService::class);

        $old = DB::transaction(fn () => $numbering->generate('work_order', $tenant->id));
        $this->assertSame('OLD-0001', $old['document_number']);
        $oldVersionId = $old['configuration_version_id'];

        // Republishing a format tweak on the SAME set continues the same
        // sequence line (never collides back at 1) while still recording
        // the new configuration_version_id going forward.
        $this->publishNumbering($configService, $tenant->id, 'TENANT', null, ['format' => 'NEW-{SEQ:4}', 'doc_code' => 'WO']);
        $new = DB::transaction(fn () => $numbering->generate('work_order', $tenant->id));
        $this->assertSame('NEW-0002', $new['document_number']);
        $this->assertNotSame($oldVersionId, $new['configuration_version_id']);

        // The historical document's own stored number is untouched — it's the caller's job to persist it once and never regenerate.
        $this->assertSame('OLD-0001', $old['document_number']);
    }

    public function test_numbering_rejects_invalid_format(): void
    {
        $tenant = $this->makeTenant(['code' => 'NUM-'.\Illuminate\Support\Str::random(4)]);
        $configService = app(ConfigurationService::class);
        $set = $configService->findOrCreateSet($tenant->id, 'NUMBERING', 'work_order', 'TENANT', null, 'Work Order Numbering');
        $draft = $configService->createDraft($set, ['format' => 'NO-SEQ-TOKEN'], null);

        $this->expectException(NumberingException::class);
        app(\App\Domain\Configuration\Services\NumberingFormatValidator::class)->validate($draft->payload);
    }
}
