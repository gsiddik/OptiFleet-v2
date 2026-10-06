<?php

namespace App\Http\Requests\Tenant;

use App\Domain\MasterData\Models\ComponentGroup;
use App\Domain\Shared\Support\Messages;
use App\Support\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $tenantId = app(TenantContext::class)->tenantId();

        return [
            // "Next Improvement Tenant Portal - Products" Section 20: Item Code is
            // server-generated (ProductController::store) and never accepted from
            // the client — deliberately absent from this rule set.
            // SKU is server-generated ([Item Type]-[Component Group abbreviation]-[sequence],
            // ProductSkuService) and, like Item Code, never accepted from the client.
            'name' => ['required', 'string', 'max:255'],
            'product_category_id' => ['required', 'uuid', Rule::exists('product_categories', 'id')->where(fn ($q) => $q->where('tenant_id', $tenantId)->orWhereNull('tenant_id'))],
            // Batch 14: the documented Item Type dropdown is exactly these 6
            // values ("Next Improvement Tenant Portal - Products": "Sparepart
            // / Consumable / Rim / Tire / Tool / Equipment"). OTHER predates
            // this document (the original Phase 4 catch-all, before RIM
            // existed as its own type — see 2026_09_25_000001's docblock) and
            // is deliberately excluded from new creation here; the database
            // CHECK constraint still accepts it so any pre-existing OTHER row
            // remains fully readable/editable (Item Type is immutable on
            // Edit regardless), this only stops NEW ones from being created.
            'product_type' => ['required', 'in:SPARE_PART,TOOL,TIRE,CONSUMABLE,EQUIPMENT,RIM'],
            'uom_id' => ['required', 'uuid', Rule::exists('uoms', 'id')->where(fn ($q) => $q->where('tenant_id', $tenantId)->orWhereNull('tenant_id'))],
            // Mandatory per the authoritative document (Hierarchical Lookup:
            // Warehouse -> Zone -> Rack -> Bin). Authoritative here, not just in
            // the UI — a request bypassing the hierarchical picker must still be
            // rejected. Existing pre-Phase-7-correction Products created before
            // this became mandatory may still carry a NULL value (no destructive
            // backfill was performed — see ProductController::update()), but
            // every NEW Product must supply one.
            'default_storage_bin_id' => ['required', 'uuid', Rule::exists('warehouse_bins', 'id')->where('tenant_id', $tenantId)],
            'brand' => ['nullable', 'string', 'max:100'],
            'manufacturer' => ['nullable', 'string', 'max:150'],
            'material' => ['nullable', 'string', 'max:100'],
            'production_year' => ['nullable', 'integer', 'min:1900', 'max:'.(date('Y') + 1)],
            'weight_kg' => ['nullable', 'numeric', 'min:0'],
            'length_mm' => ['nullable', 'numeric', 'min:0'],
            'width_mm' => ['nullable', 'numeric', 'min:0'],
            'height_mm' => ['nullable', 'numeric', 'min:0'],
            'image_url' => ['nullable', 'string', 'max:255'],
            'manufacturer_part_number' => ['nullable', 'string', 'max:100'],
            'description' => ['nullable', 'string'],
            'track_serial_number' => ['nullable', 'boolean'],
            'track_batch' => ['nullable', 'boolean'],
            'status' => ['nullable', 'in:ACTIVE,INACTIVE'],
            // Reference (new) tread depth for TIRE products — the default D_new of a used tire inspection.
            'reference_tread_depth_mm' => ['nullable', 'numeric', 'min:0.01'],
            // Mechanical classification. Component Group + Category are mandatory for
            // Sparepart/Consumable/Tire/Rim, Subcategory is optional for every Item Type;
            // hierarchy, availability and Item Type applicability: ComponentClassificationService.
            'component_group_id' => ['nullable', 'uuid'],
            'component_category_id' => ['nullable', 'uuid'],
            'component_subcategory_id' => ['nullable', 'uuid'],
            // Where the New Product form was opened: TIRE = the Tires page "New Tire" button, RIM =
            // Tire Management → Rim "New Rim"; both fix the Item Type (TIRE / RIM) and Component Group
            // = Wheel & Tyre System. Absent = Products.
            'creation_context' => ['nullable', 'in:PRODUCT,TIRE,RIM'],
        ];
    }

    /** The Tire / Rim context's locked fields are enforced here, not only by the disabled inputs. */
    public function after(): array
    {
        return [function (Validator $validator) {
            $context = $this->input('creation_context');
            if (! in_array($context, ['TIRE', 'RIM'], true) || $validator->errors()->isNotEmpty()) {
                return;
            }
            if ($context === 'TIRE' && $this->input('product_type') !== 'TIRE') {
                $validator->errors()->add('product_type', 'A product created from Tires must have Item Type TIRE.');
            }
            if ($context === 'RIM' && $this->input('product_type') !== 'RIM') {
                $validator->errors()->add('product_type', Messages::localized('rim.errors.contextItemType'));
            }
            // A rim is a serialized physical item: every unit received or registered has a serial number.
            if ($context === 'RIM' && filter_var($this->input('track_serial_number'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) !== true) {
                $validator->errors()->add('track_serial_number', Messages::localized('rim.errors.contextSerialTracked'));
            }
            $group = ComponentGroup::query()->find($this->input('component_group_id'));
            if ($group?->code !== self::TIRE_COMPONENT_GROUP_CODE) {
                $validator->errors()->add('component_group_id', $context === 'RIM'
                    ? Messages::localized('rim.errors.contextComponentGroup')
                    : 'A product created from Tires must be in the Wheel & Tyre System component group.');
            }
        }];
    }

    public const TIRE_COMPONENT_GROUP_CODE = 'CG-TYRE';
}
