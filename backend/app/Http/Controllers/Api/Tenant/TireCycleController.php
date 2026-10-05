<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Domain\AccessControl\Services\PermissionService;
use App\Domain\Tire\Models\Tire;
use App\Domain\Tire\Models\TireCyclePhoto;
use App\Domain\Tire\Services\TireCycleService;
use App\Domain\Tire\Services\TireInventoryService;
use App\Http\Controllers\Controller;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/** Used Tire Management → Retread: open a retread / repair cycle, receive it, history and photos. */
class TireCycleController extends Controller
{
    public function __construct(
        private readonly TireCycleService $cycles,
        private readonly TireInventoryService $inventory,
        private readonly PermissionService $permissions,
        private readonly TenantContext $context,
    ) {}

    /** "Tires in Retread / Repair Cycle" with each tire's open cycle. */
    public function index(Request $request)
    {
        return $this->ok($this->cycles->openList($this->context->tenantId(), $this->context->user(), $request->string('search')->trim()->value() ?: null));
    }

    /** Open Cycle (Retread Form): Processed At (vendor), Estimated Price, 1–3 photos, notes. */
    public function store(Request $request, Tire $tire)
    {
        $this->authorizeTire($tire);
        $kind = $this->cycles->kindFor($tire);
        $this->require(strtolower($kind) === 'retread' ? 'tire_retread.send' : 'tire_repair.send');
        $validated = $request->validate([
            'partner_id' => ['required', 'uuid'],
            'estimated_price' => ['required', 'numeric', 'gt:0'],
            'photos' => ['required', 'array', 'min:1', 'max:'.TireCycleService::MAX_PHOTOS],
            'photos.*' => ['required', 'file'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ], [
            'photos.required' => 'Upload at least one photo.',
            'photos.max' => 'Upload at most '.TireCycleService::MAX_PHOTOS.' photos.',
        ]);

        $cycle = $this->cycles->open($tire, $validated['partner_id'], (string) $request->input('estimated_price'), $request->file('photos'), $validated['notes'] ?? null, $this->context->user()->id);

        return $this->ok($this->cycles->present($kind, $cycle), 201);
    }

    /** Receive: the tire is back; it must be inspected again (Tire Inspection) to complete the cycle. */
    public function receive(string $kind, string $cycle)
    {
        $model = $this->find($kind, $cycle);
        $this->require($kind === 'retread' ? 'tire_retread.receive' : 'tire_repair.receive');

        return $this->ok($this->cycles->present(strtoupper($kind), $this->cycles->receive($model, $this->context->user()->id)->load(['partner', 'photos'])));
    }

    /** Retread History of a tire (retread and repair cycles). */
    public function history(Tire $tire)
    {
        $this->authorizeTire($tire);

        return $this->ok($this->cycles->history($tire));
    }

    /**
     * Used Tire Management → Scrap → Recently Scrapped: SCRAPPED tires (newest first, data scope)
     * with their active sale, if any (a tire in an active sale cannot be selected again).
     */
    public function scrapped(Request $request)
    {
        $tenantId = $this->context->tenantId();
        $query = DB::table('tires')->leftJoin('products as p', 'p.id', '=', 'tires.product_id')
            ->leftJoin('spare_part_sales as s', fn ($j) => $j->on('s.tire_id', '=', 'tires.id')->whereIn('s.status', ['DRAFT', 'PENDING_APPROVAL', 'APPROVED']))
            ->where('tires.tenant_id', $tenantId)->whereNull('tires.deleted_at')->where('tires.current_status', 'SCRAPPED')
            ->when($request->string('search')->trim()->value(), fn ($q, $search) => $q->where('tires.serial_number', 'ilike', "%{$search}%"))
            ->when(array_filter(explode(',', (string) $request->query('ids')), fn ($id) => Str::isUuid($id)), fn ($q, $ids) => $q->whereIn('tires.id', $ids))
            ->select(['tires.id', 'tires.serial_number', 'tires.current_status', 'tires.updated_at as scrapped_at', 'tires.product_id', 'p.name as product_name', 's.id as sale_id', 's.status as sale_status'])
            ->orderByDesc('tires.updated_at')->orderBy('tires.serial_number');

        return $this->paginated($this->inventory->scopeToUser($query, $tenantId, $this->context->user())->paginate(min(100, $request->integer('per_page', 20))));
    }

    public function photo(string $kind, string $cycle, string $photo)
    {
        $model = $this->find($kind, $cycle);
        $file = TireCyclePhoto::query()->where('cycle_type', strtoupper($kind))->where('cycle_id', $model->id)->findOrFail($photo);

        return Storage::disk($file->disk)->response($file->path, $file->original_filename, ['Content-Type' => $file->mime_type]);
    }

    private function find(string $kind, string $cycle)
    {
        $class = TireCycleService::KINDS[strtoupper($kind)] ?? abort(404);
        $model = $class::query()->where('tenant_id', $this->context->tenantId())->findOrFail($cycle);
        $this->authorizeTire(Tire::query()->findOrFail($model->tire_id));

        return $model;
    }

    private function authorizeTire(Tire $tire): void
    {
        abort_unless($tire->tenant_id === $this->context->tenantId(), 404);
        $visible = $this->inventory->scopeToUser(DB::table('tires')->where('tires.id', $tire->id), $tire->tenant_id, $this->context->user())->exists();
        abort_unless($visible, 403, 'This tire is outside your assigned data scope.');
    }

    private function require(string $permission): void
    {
        abort_unless($this->permissions->userHasPermission($this->context->user(), $permission, $this->context->tenantId()), 403, "This action requires the {$permission} permission.");
    }
}
