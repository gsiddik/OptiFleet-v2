<?php

namespace Tests\Feature;

use App\Domain\Configuration\Models\ConfigurationSet;
use App\Domain\Configuration\Services\ConfigurationException;
use App\Domain\Configuration\Services\ConfigurationService;
use App\Domain\Configuration\Services\EffectiveConfigurationResolver;
use Tests\TestCase;

class ConfigurationCoreTest extends TestCase
{
    public function test_draft_publish_archive_lifecycle_preserves_history(): void
    {
        $tenant = $this->makeTenant(['code' => 'CFG-'.\Illuminate\Support\Str::random(4)]);
        $service = app(ConfigurationService::class);

        $set = $service->findOrCreateSet($tenant->id, 'NUMBERING', 'work_order', 'TENANT', null, 'Work Order Numbering');
        $v1 = $service->createDraft($set, ['format' => '{DOC}/{YYYY}/{SEQ:6}'], null);
        $this->assertSame('DRAFT', $v1->status);

        $published1 = $service->publish($v1, null);
        $this->assertSame('PUBLISHED', $published1->status);
        $this->assertSame(1, $published1->version_number);

        $v2 = $service->createDraft($set, ['format' => '{DOC}-{YYYY}-{SEQ:6}'], null);
        $published2 = $service->publish($v2, null);

        $published1->refresh();
        $this->assertSame('ARCHIVED', $published1->status);
        $this->assertSame('PUBLISHED', $published2->status);
        $this->assertSame(2, $published2->version_number);

        // History preserved: both versions still exist, unchanged payloads.
        $this->assertSame('{DOC}/{YYYY}/{SEQ:6}', $v1->fresh()->payload['format']);
        $this->assertSame('{DOC}-{YYYY}-{SEQ:6}', $v2->fresh()->payload['format']);
    }

    public function test_only_draft_can_be_published_and_only_published_can_be_archived(): void
    {
        $tenant = $this->makeTenant(['code' => 'CFG-'.\Illuminate\Support\Str::random(4)]);
        $service = app(ConfigurationService::class);
        $set = $service->findOrCreateSet($tenant->id, 'NUMBERING', 'work_order', 'TENANT', null, 'Work Order Numbering');
        $v1 = $service->createDraft($set, ['format' => 'X'], null);

        $this->expectException(ConfigurationException::class);
        $service->archive($v1, null);
    }

    public function test_inheritance_resolves_most_specific_scope_first(): void
    {
        $tenant = $this->makeTenant(['code' => 'CFG-'.\Illuminate\Support\Str::random(4)]);
        $branch = $this->makeBranch($tenant);
        $workshop = $this->makeWorkshop($tenant, $branch);
        $service = app(ConfigurationService::class);
        $resolver = app(EffectiveConfigurationResolver::class);

        $tenantSet = $service->findOrCreateSet($tenant->id, 'NUMBERING', 'work_order', 'TENANT', null, 'Tenant Default');
        $service->publish($service->createDraft($tenantSet, ['format' => 'TENANT-FORMAT'], null), null);

        $result = $resolver->resolve('NUMBERING', 'work_order', $tenant->id, $branch->id, $workshop->id);
        $this->assertSame('TENANT-FORMAT', $result->payload['format']);

        $branchSet = $service->findOrCreateSet($tenant->id, 'NUMBERING', 'work_order', 'BRANCH', $branch->id, 'Branch Override');
        $service->publish($service->createDraft($branchSet, ['format' => 'BRANCH-FORMAT'], null), null);

        $result = $resolver->resolve('NUMBERING', 'work_order', $tenant->id, $branch->id, $workshop->id);
        $this->assertSame('BRANCH-FORMAT', $result->payload['format']);

        $workshopSet = $service->findOrCreateSet($tenant->id, 'NUMBERING', 'work_order', 'WORKSHOP', $workshop->id, 'Workshop Override');
        $service->publish($service->createDraft($workshopSet, ['format' => 'WORKSHOP-FORMAT'], null), null);

        $result = $resolver->resolve('NUMBERING', 'work_order', $tenant->id, $branch->id, $workshop->id);
        $this->assertSame('WORKSHOP-FORMAT', $result->payload['format']);

        // A different workshop in the same branch still falls back to the branch override.
        $otherWorkshop = $this->makeWorkshop($tenant, $branch, ['code' => 'WS-OTHER']);
        $result = $resolver->resolve('NUMBERING', 'work_order', $tenant->id, $branch->id, $otherWorkshop->id);
        $this->assertSame('BRANCH-FORMAT', $result->payload['format']);
    }

    public function test_falls_back_to_platform_default_when_tenant_has_no_configuration(): void
    {
        $tenant = $this->makeTenant(['code' => 'CFG-'.\Illuminate\Support\Str::random(4)]);
        $service = app(ConfigurationService::class);
        $resolver = app(EffectiveConfigurationResolver::class);

        $platformSet = $service->findOrCreateSet(null, 'NUMBERING', 'purchase_order', 'TENANT', null, 'Platform Default', true);
        $service->publish($service->createDraft($platformSet, ['format' => 'PLATFORM-FORMAT'], null), null);

        $result = $resolver->resolve('NUMBERING', 'purchase_order', $tenant->id);
        $this->assertSame('PLATFORM-FORMAT', $result->payload['format']);
    }

    public function test_configuration_cache_does_not_leak_across_tenants(): void
    {
        $tenantA = $this->makeTenant(['code' => 'CFGA-'.\Illuminate\Support\Str::random(4)]);
        $tenantB = $this->makeTenant(['code' => 'CFGB-'.\Illuminate\Support\Str::random(4)]);
        $service = app(ConfigurationService::class);
        $resolver = app(EffectiveConfigurationResolver::class);

        $setA = $service->findOrCreateSet($tenantA->id, 'NUMBERING', 'work_order', 'TENANT', null, 'A');
        $service->publish($service->createDraft($setA, ['format' => 'A-FORMAT'], null), null);
        $setB = $service->findOrCreateSet($tenantB->id, 'NUMBERING', 'work_order', 'TENANT', null, 'B');
        $service->publish($service->createDraft($setB, ['format' => 'B-FORMAT'], null), null);

        $this->assertSame('A-FORMAT', $resolver->resolve('NUMBERING', 'work_order', $tenantA->id)->payload['format']);
        $this->assertSame('B-FORMAT', $resolver->resolve('NUMBERING', 'work_order', $tenantB->id)->payload['format']);
    }

    public function test_publishing_new_version_invalidates_cache(): void
    {
        $tenant = $this->makeTenant(['code' => 'CFG-'.\Illuminate\Support\Str::random(4)]);
        $service = app(ConfigurationService::class);
        $resolver = app(EffectiveConfigurationResolver::class);

        $set = $service->findOrCreateSet($tenant->id, 'NUMBERING', 'work_order', 'TENANT', null, 'Work Order Numbering');
        $service->publish($service->createDraft($set, ['format' => 'V1'], null), null);
        $this->assertSame('V1', $resolver->resolve('NUMBERING', 'work_order', $tenant->id)->payload['format']);

        $service->publish($service->createDraft($set, ['format' => 'V2'], null), null);
        $this->assertSame('V2', $resolver->resolve('NUMBERING', 'work_order', $tenant->id)->payload['format']);
    }
}
