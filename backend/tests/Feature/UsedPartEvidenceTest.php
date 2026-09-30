<?php

namespace Tests\Feature;

use App\Domain\Inventory\Services\InventoryService;
use App\Domain\WorkOrder\Models\UsedPartInspectionEvidence;
use App\Domain\WorkOrder\Models\WorkOrderPartReturn;
use App\Domain\WorkOrder\Services\WorkOrderService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Used Sparepart Processing — Evidence Photo upload: JPG/PNG only (checked from the file content),
 * max 3 MB, private storage under a server-generated name, authorized viewing, tenant isolated.
 */
class UsedPartEvidenceTest extends TestCase
{
    private function setUpPendingInspection(): array
    {
        Storage::fake('local');
        $tenant = $this->makeTenant(['code' => 'UPE-'.Str::random(4)]);
        foreach (['VEHICLE', 'MAINTENANCE', 'WORKSHOP', 'WORK_ORDER', 'INVENTORY'] as $module) {
            $this->grantModule($tenant, $module);
        }
        $branch = $this->makeBranch($tenant);
        $workshop = $this->makeWorkshop($tenant, $branch);
        $warehouse = $this->makeWarehouse($tenant, $branch, $workshop);
        $vehicle = $this->makeVehicle($tenant, $branch, $this->makeVehicleCategory(), ['default_workshop_id' => $workshop->id]);
        $product = $this->makeProduct($tenant);
        app(InventoryService::class)->receive($warehouse, $product, 10, 15, 'OPENING', null, null, null);
        $service = app(WorkOrderService::class);
        $wo = $service->start($service->schedule($service->assign($service->approve($service->submit(
            $service->create($vehicle, ['workshop_id' => $workshop->id, 'maintenance_type' => 'CORRECTIVE'], null)
        )))));
        $this->consumeOnWorkOrder($wo, $product, 2, $warehouse);
        [, $token] = $this->makeTenantUser($tenant, ['maintenance_job.manage', 'used_part.view', 'used_part.inspect']);
        $headers = $this->authHeaders($token);

        $componentId = $this->postJson("/api/v1/app/work-orders/{$wo->id}/removed-components", ['product_id' => $product->id, 'quantity' => 2, 'condition' => 'GOOD'], $headers)
            ->assertStatus(201)->json('data.id');
        $return = WorkOrderPartReturn::query()->where('work_order_removed_component_id', $componentId)->firstOrFail();
        $this->postJson("/api/v1/app/used-part-returns/{$return->id}/receive", ['warehouse_id' => $warehouse->id], $headers)->assertOk();

        return [$tenant, $return->fresh(), $headers];
    }

    /** Multipart/binary requests bypass TestCase::json(), so reset the memoized guard here too. */
    private function upload(WorkOrderPartReturn $return, UploadedFile $file, array $headers)
    {
        $this->app['auth']->forgetGuards();

        return $this->post("/api/v1/app/used-part-returns/{$return->id}/evidence", ['file' => $file], $headers + ['Accept' => 'application/json']);
    }

    private function viewEvidence(WorkOrderPartReturn $return, string $evidenceId, array $headers)
    {
        $this->app['auth']->forgetGuards();

        return $this->get("/api/v1/app/used-part-returns/{$return->id}/evidence/{$evidenceId}", $headers + ['Accept' => 'application/json']);
    }

    public function test_jpg_and_png_evidence_is_stored_privately_and_viewable(): void
    {
        [, $return, $headers] = $this->setUpPendingInspection();

        $jpg = $this->upload($return, UploadedFile::fake()->image('front view.jpg', 300, 200), $headers)->assertCreated();
        $this->upload($return, UploadedFile::fake()->image('side.PNG', 300, 200), $headers)->assertCreated();
        $this->assertArrayNotHasKey('path', $jpg->json('data'), 'The storage path is never exposed.');
        $this->assertSame('front view.jpg', $jpg->json('data.original_filename'));

        $stored = UsedPartInspectionEvidence::query()->findOrFail($jpg->json('data.id'));
        $this->assertMatchesRegularExpression('#^used-part-evidence/'.$return->tenant_id.'/[0-9a-f-]{36}\.jpg$#', $stored->path, 'Server-generated name, never the client filename.');
        Storage::disk('local')->assertExists($stored->path);

        $list = $this->getJson('/api/v1/app/used-part-returns?disposition_status=PENDING_INSPECTION', $headers)->assertOk()->json('data.0.evidence_photos');
        $this->assertCount(2, $list);
        $this->viewEvidence($return, $stored->id, $headers)->assertOk()->assertHeader('Content-Type', 'image/jpeg');
    }

    public function test_other_types_spoofed_files_and_oversized_files_are_rejected(): void
    {
        [, $return, $headers] = $this->setUpPendingInspection();

        $this->upload($return, UploadedFile::fake()->create('scan.pdf', 50, 'application/pdf'), $headers)->assertStatus(422)->assertJsonValidationErrors('file');
        $this->upload($return, UploadedFile::fake()->image('photo.gif'), $headers)->assertStatus(422);
        // Declared as .jpg but the content is not an image: the content check wins over the name.
        // (A real temp file, since Laravel's fake files report a MIME type derived from the name.)
        $spoof = tempnam(sys_get_temp_dir(), 'spoof');
        file_put_contents($spoof, '<?php echo "not an image";');
        $this->upload($return, new UploadedFile($spoof, 'fake.jpg', null, null, true), $headers)->assertStatus(422);
        $this->upload($return, UploadedFile::fake()->image('big.jpg')->size(3073), $headers)->assertStatus(422)
            ->assertJsonPath('errors.file.0', 'The evidence photo may not be larger than 3 MB.');
        $this->upload($return, UploadedFile::fake()->image('exact.jpg')->size(3072), $headers)->assertCreated();

        $this->assertSame(1, UsedPartInspectionEvidence::query()->where('work_order_part_return_id', $return->id)->count());
    }

    public function test_evidence_is_locked_after_inspection_and_requires_permission(): void
    {
        [$tenant, $return, $headers] = $this->setUpPendingInspection();
        $evidenceId = $this->upload($return, UploadedFile::fake()->image('a.jpg'), $headers)->assertCreated()->json('data.id');

        [, $viewer] = $this->makeTenantUser($tenant, ['used_part.view']);
        $this->upload($return, UploadedFile::fake()->image('b.jpg'), $this->authHeaders($viewer))->assertForbidden();
        $this->deleteJson("/api/v1/app/used-part-returns/{$return->id}/evidence/{$evidenceId}", [], $this->authHeaders($viewer))->assertForbidden();
        $this->viewEvidence($return, $evidenceId, $this->authHeaders($viewer))->assertOk();

        $this->postJson("/api/v1/app/used-part-returns/{$return->id}/inspect", ['accepted_quantity' => 2, 'condition' => 'USED_GOOD'], $headers)->assertOk();
        $this->upload($return, UploadedFile::fake()->image('late.jpg'), $headers)->assertStatus(422);
        $this->deleteJson("/api/v1/app/used-part-returns/{$return->id}/evidence/{$evidenceId}", [], $headers)->assertStatus(422);
        $this->assertSame(1, UsedPartInspectionEvidence::query()->where('work_order_part_return_id', $return->id)->count());
    }

    public function test_evidence_is_tenant_isolated(): void
    {
        [, $return, $headers] = $this->setUpPendingInspection();
        $evidenceId = $this->upload($return, UploadedFile::fake()->image('a.jpg'), $headers)->assertCreated()->json('data.id');

        $other = $this->makeTenant(['code' => 'UPX-'.Str::random(4)]);
        $this->grantModule($other, 'INVENTORY');
        $this->grantModule($other, 'WORK_ORDER');
        [, $foreign] = $this->makeTenantUser($other, ['used_part.view', 'used_part.inspect']);
        $this->viewEvidence($return, $evidenceId, $this->authHeaders($foreign))->assertNotFound();
        $this->upload($return, UploadedFile::fake()->image('x.jpg'), $this->authHeaders($foreign))->assertNotFound();
    }
}
