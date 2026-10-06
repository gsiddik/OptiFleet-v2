<?php

namespace Tests\Feature;

use App\Domain\Configuration\Services\DocumentTemplateContextBuilder;
use App\Domain\Configuration\Services\DocumentTemplateService;
use App\Domain\Configuration\Services\TemplateValidationException;
use App\Domain\DocumentGeneration\Models\DocumentGeneration;
use App\Domain\DocumentGeneration\Services\DocumentGenerationService;
use App\Domain\DocumentGeneration\Support\DocumentSource;
use App\Domain\WorkOrder\Models\WorkOrder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use Tests\TestCase;

/**
 * PRINT_LOCALE_SNAPSHOT (i18n structural preparation S6): every print renders a DocumentGeneration that
 * pins its locale and template version. Cases A–D from the owner's specification, plus the language
 * priority, history immutability, per-locale template bodies and tenant isolation.
 */
class DocumentGenerationLocaleTest extends TestCase
{
    /** @return array{0: \App\Domain\Identity\Models\Tenant, 1: WorkOrder, 2: array, 3: \App\Models\User, 4: \App\Domain\Configuration\Models\ConfigurationSet} */
    private function setUpWorkOrder(string $templateHtml = 'V1 {{document_number}} | {{work_order.created_at}} | {{generated_at}}'): array
    {
        $tenant = $this->makeTenant(['code' => 'DGL-'.Str::random(4)]);
        foreach (['VEHICLE', 'MAINTENANCE', 'WORKSHOP', 'WORK_ORDER'] as $module) {
            $this->grantModule($tenant, $module);
        }
        $branch = $this->makeBranch($tenant);
        $workshop = $this->makeWorkshop($tenant, $branch);
        $vehicle = $this->makeVehicle($tenant, $branch, $this->makeVehicleCategory(), ['default_workshop_id' => $workshop->id]);
        [$user, $token] = $this->makeTenantUser($tenant, ['work_order.view', 'work_order.create']);
        $headers = $this->authHeaders($token);

        $templates = app(DocumentTemplateService::class);
        $set = $templates->findOrCreateSet($tenant->id, 'work_order', 'TENANT', null, 'WO Template');
        $templates->publish($templates->createDraft($set, ['html' => $templateHtml], null), 'work_order', null);

        $woId = $this->postJson('/api/v1/app/work-orders', [
            'vehicle_id' => $vehicle->id, 'workshop_id' => $workshop->id, 'maintenance_type' => 'CORRECTIVE', 'current_odometer' => 1000,
        ], $headers)->assertStatus(201)->json('data.id');

        return [$tenant, WorkOrder::query()->findOrFail($woId), $headers, $user, $set];
    }

    private function source(WorkOrder $workOrder): DocumentSource
    {
        return new DocumentSource(
            'work_order', 'work_order', $workOrder->id, $workOrder->tenant_id, $workOrder->wo_number.'.pdf',
            fn (string $locale) => DocumentTemplateContextBuilder::forWorkOrder($workOrder, $locale),
            branchId: $workOrder->branch_id, workshopId: $workOrder->workshop_id,
        );
    }

    private function html(WorkOrder $workOrder, string $generationId): string
    {
        return app(DocumentGenerationService::class)->renderHtml($this->source($workOrder), DocumentGeneration::query()->findOrFail($generationId));
    }

    public function test_reprint_keeps_locale_and_template_version_new_version_adds_history(): void
    {
        [$tenant, $wo, $headers, $user, $set] = $this->setUpWorkOrder();
        $print = "/api/v1/app/work-orders/{$wo->id}/print";

        // A: generated with an explicit "id" → the generation stores id.
        $first = $this->get($print.'?locale=id', $headers)->assertOk();
        $this->assertSame('application/pdf', $first->headers->get('Content-Type'));
        $this->assertSame('id', $first->headers->get('X-Document-Locale'));
        $firstId = $first->headers->get('X-Document-Generation-Id');
        $generation = DocumentGeneration::query()->findOrFail($firstId);
        $this->assertSame(['id', 'work_order', 'work_order', $wo->id, $user->id, 1], [
            $generation->locale, $generation->document_type, $generation->source_entity_type, $generation->source_entity_id, $generation->generated_by, $generation->template_version,
        ]);
        $this->assertSame($set->id, $generation->template_id);
        $this->assertStringContainsString(' | '.$wo->created_at->locale('id')->isoFormat('D MMMM YYYY'), $this->html($wo, $firstId));

        // B: user and tenant now prefer en; a reprint stays id and creates nothing.
        $user->forceFill(['preferred_locale' => 'en'])->save();
        $tenant->forceFill(['default_locale' => 'en'])->save();
        $again = $this->get($print, $headers)->assertOk();
        $this->assertSame([$firstId, 'id'], [$again->headers->get('X-Document-Generation-Id'), $again->headers->get('X-Document-Locale')]);
        $this->assertSame(1, DocumentGeneration::query()->where('source_entity_id', $wo->id)->count());

        // D: the template changes; the historical generation still renders its own version.
        $templates = app(DocumentTemplateService::class);
        $templates->publish($templates->createDraft($set, ['html' => 'V2 {{document_number}}'], null), 'work_order', null);
        $reprint = $this->get($print.'?generation='.$firstId, $headers)->assertOk();
        $this->assertSame('1', $reprint->headers->get('X-Document-Template-Version'));
        $this->assertStringStartsWith('V1 '.$wo->wo_number, $this->html($wo, $firstId));
        $this->assertSame('1', $this->get($print, $headers)->headers->get('X-Document-Template-Version'), 'A plain print is a reprint of the latest generation.');

        // C: Generate New Version with an explicit en → a new record; history unchanged.
        $created = $this->postJson($print.'/generations', ['locale' => 'en'], $headers)->assertCreated()->json('data');
        $this->assertSame(['en', 2, 2], [$created['locale'], $created['template_version'], $created['sequence']]);
        $this->assertNotSame($firstId, $created['id']);
        $this->assertStringStartsWith('V2 '.$wo->wo_number, $this->html($wo, $created['id']));
        $this->assertSame('id', DocumentGeneration::query()->findOrFail($firstId)->locale);

        $latest = $this->get($print, $headers)->assertOk();
        $this->assertSame([$created['id'], 'en'], [$latest->headers->get('X-Document-Generation-Id'), $latest->headers->get('X-Document-Locale')]);
        $this->assertSame('id', $this->get($print.'?generation='.$firstId, $headers)->headers->get('X-Document-Locale'));

        $history = $this->getJson($print.'/generations', $headers)->assertOk()->json('data');
        $this->assertSame([[$created['id'], 'en', 2], [$firstId, 'id', 1]], array_map(fn ($g) => [$g['id'], $g['locale'], $g['sequence']], $history));
    }

    public function test_document_language_priority_is_explicit_then_user_then_tenant_then_english(): void
    {
        [$tenant, $wo, $headers, $user] = $this->setUpWorkOrder();
        $generate = fn (array $body = []) => $this->postJson("/api/v1/app/work-orders/{$wo->id}/print/generations", $body, $headers)->assertCreated()->json('data.locale');

        $this->assertSame('en', $generate());
        $tenant->forceFill(['default_locale' => 'id'])->save();
        $this->assertSame('id', $generate());
        $user->forceFill(['preferred_locale' => 'en'])->save();
        $this->assertSame('en', $generate());
        $this->assertSame('id', $generate(['locale' => 'id']));

        $this->postJson("/api/v1/app/work-orders/{$wo->id}/print/generations", ['locale' => 'fr'], $headers)->assertStatus(422)->assertJsonValidationErrors('locale');
        $this->get("/api/v1/app/work-orders/{$wo->id}/print?locale=fr", $headers + ['Accept' => 'application/json'])->assertStatus(422);
    }

    public function test_generation_records_are_immutable(): void
    {
        [, $wo, $headers] = $this->setUpWorkOrder();
        $id = $this->get("/api/v1/app/work-orders/{$wo->id}/print", $headers)->assertOk()->headers->get('X-Document-Generation-Id');
        $generation = DocumentGeneration::query()->findOrFail($id);

        try {
            $generation->update(['locale' => 'id']);
            $this->fail('A generation must not be updated through the model.');
        } catch (LogicException) {
        }
        try {
            DB::transaction(fn () => DB::table('document_generations')->where('id', $id)->update(['locale' => 'id']));
            $this->fail('A generation must not be updated in the database.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('immutable', $e->getMessage());
        }
        $this->assertSame('en', $generation->fresh()->locale);
    }

    public function test_a_template_version_may_carry_a_body_per_locale_and_falls_back_to_its_single_body(): void
    {
        [, $wo, $headers, , $set] = $this->setUpWorkOrder();
        $templates = app(DocumentTemplateService::class);
        $templates->publish($templates->createDraft($set, ['html' => 'EN {{document_number}}', 'locales' => ['id' => ['html' => 'ID {{document_number}}']]], null), 'work_order', null);
        $print = "/api/v1/app/work-orders/{$wo->id}/print/generations";

        $id = $this->postJson($print, ['locale' => 'id'], $headers)->assertCreated()->json('data.id');
        $en = $this->postJson($print, ['locale' => 'en'], $headers)->assertCreated()->json('data.id');
        $this->assertSame('ID '.$wo->wo_number, $this->html($wo, $id));
        $this->assertSame('EN '.$wo->wo_number, $this->html($wo, $en));

        $this->expectException(TemplateValidationException::class);
        $templates->publish($templates->createDraft($set, ['html' => 'ok', 'locales' => ['id' => ['html' => '{{secret.field}}']]], null), 'work_order', null);
    }

    public function test_generations_are_scoped_to_their_tenant_and_document(): void
    {
        [, $wo, $headers] = $this->setUpWorkOrder();
        [, $otherWo, $otherHeaders] = $this->setUpWorkOrder();
        $this->app['auth']->forgetGuards();
        $id = $this->get("/api/v1/app/work-orders/{$wo->id}/print", $headers)->assertOk()->headers->get('X-Document-Generation-Id');
        $this->app['auth']->forgetGuards();

        // Another tenant can neither print the work order nor reach its generation through its own document.
        $this->getJson("/api/v1/app/work-orders/{$wo->id}/print", $otherHeaders)->assertNotFound();
        $this->getJson("/api/v1/app/work-orders/{$wo->id}/print/generations", $otherHeaders)->assertNotFound();
        $this->postJson("/api/v1/app/work-orders/{$wo->id}/print/generations", [], $otherHeaders)->assertNotFound();
        $this->getJson("/api/v1/app/work-orders/{$otherWo->id}/print?generation={$id}", $otherHeaders)->assertNotFound();
        $this->assertSame([], $this->getJson("/api/v1/app/work-orders/{$otherWo->id}/print/generations", $otherHeaders)->json('data'));
    }

    public function test_the_first_print_creates_one_generation_and_later_prints_reuse_it(): void
    {
        [, $wo, $headers] = $this->setUpWorkOrder();
        $ids = [];
        foreach (range(1, 3) as $_) {
            $ids[] = $this->get("/api/v1/app/work-orders/{$wo->id}/print", $headers)->assertOk()->headers->get('X-Document-Generation-Id');
        }
        $this->assertCount(1, array_unique($ids));
        $this->assertSame(1, DocumentGeneration::query()->where('source_entity_id', $wo->id)->count());
    }
}
