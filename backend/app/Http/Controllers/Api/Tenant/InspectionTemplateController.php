<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Domain\Inspection\Models\InspectionTemplate;
use App\Domain\Inspection\Models\InspectionTemplateItem;
use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\AddInspectionTemplateItemRequest;
use App\Http\Requests\Tenant\StoreInspectionTemplateRequest;
use App\Support\TenantContext;
use Illuminate\Http\Request;

class InspectionTemplateController extends Controller
{
    public function __construct(private readonly TenantContext $context) {}

    public function index(Request $request)
    {
        $query = InspectionTemplate::query()->with('vehicleCategory')->withCount('items');

        if ($type = $request->string('inspection_type')->value()) {
            $query->where('inspection_type', $type);
        }
        if ($status = $request->string('status')->value()) {
            $query->where('status', $status);
        }

        return $this->paginated($query->latest('created_at')->paginate($request->integer('per_page', 20)));
    }

    private const ODOMETER_MANDATORY_TYPES = ['PRE_TRIP', 'POST_TRIP', 'PERIODIC', 'WORKSHOP', 'MAINTENANCE'];

    public function store(StoreInspectionTemplateRequest $request)
    {
        $template = InspectionTemplate::query()->create($request->validated() + ['status' => 'DRAFT']);

        // Section 9: every new template of these types must start with a
        // system-mandatory, non-removable "Odometer" checklist item. Created
        // once here, at template creation, so re-opening/re-saving the form
        // (which only adds/removes items through separate endpoints) can
        // never duplicate it.
        if (in_array($template->inspection_type, self::ODOMETER_MANDATORY_TYPES, true)) {
            InspectionTemplateItem::query()->create([
                'inspection_template_id' => $template->id,
                'item_text' => 'Odometer',
                'input_type' => 'NUMBER',
                'required' => true,
                'sequence' => 0,
                'status' => 'ACTIVE',
                'is_system' => true,
            ]);
        }

        return $this->ok($template->load('items'), 201);
    }

    public function show(InspectionTemplate $inspectionTemplate)
    {
        $this->authorizeTenant($inspectionTemplate);

        return $this->ok($inspectionTemplate->load(['items.componentGroup', 'vehicleCategory']));
    }

    public function update(StoreInspectionTemplateRequest $request, InspectionTemplate $inspectionTemplate)
    {
        $this->authorizeTenant($inspectionTemplate);
        $inspectionTemplate->update($request->validated());

        return $this->ok($inspectionTemplate->fresh());
    }

    public function activate(InspectionTemplate $inspectionTemplate)
    {
        $this->authorizeTenant($inspectionTemplate);
        $inspectionTemplate->update(['status' => 'ACTIVE']);

        return $this->ok($inspectionTemplate->fresh());
    }

    public function archive(InspectionTemplate $inspectionTemplate)
    {
        $this->authorizeTenant($inspectionTemplate);
        $inspectionTemplate->update(['status' => 'ARCHIVED']);

        return $this->ok($inspectionTemplate->fresh());
    }

    public function addItem(AddInspectionTemplateItemRequest $request, InspectionTemplate $inspectionTemplate)
    {
        $this->authorizeTenant($inspectionTemplate);

        $item = InspectionTemplateItem::query()->create($request->validated() + [
            'inspection_template_id' => $inspectionTemplate->id,
            'sequence' => $request->input('sequence', $inspectionTemplate->items()->count()),
        ]);

        return $this->ok($item, 201);
    }

    public function removeItem(InspectionTemplate $inspectionTemplate, InspectionTemplateItem $item)
    {
        $this->authorizeTenant($inspectionTemplate);
        abort_unless($item->inspection_template_id === $inspectionTemplate->id, 404);
        abort_if($item->is_system, 403, 'This is a system-required item and cannot be removed.');

        $item->delete();

        return $this->message('Item removed.');
    }

    private function authorizeTenant(InspectionTemplate $template): void
    {
        abort_unless($template->tenant_id === $this->context->tenantId(), 404);
    }
}
