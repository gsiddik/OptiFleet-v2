<?php

namespace App\Domain\Tire\Services;

use App\Domain\Shared\Services\PrivateDocumentStorage;
use App\Domain\Shared\Support\Messages;
use App\Domain\Tire\Models\Tire;
use App\Domain\Tire\Models\TireCyclePhoto;
use App\Domain\Tire\Models\TireRepair;
use App\Domain\Tire\Models\TireRetread;
use App\Models\User;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Used Tire Management → Retread: a retread (or repair — a kind of retread) cycle
 *
 *   Open Cycle   vendor (Processed At), Estimated Price, 1–3 photos, notes → SENT ("in process");
 *                the tire stays in "Tires in Retread / Repair Cycle"
 *   Receive      SENT → RECEIVED; the tire must be inspected again (Tire Inspection)
 *   Inspection   submitted → FINAL_INSPECTED; approved → APPROVED (completed) with the
 *                inspection's disposition as final_status — only then the cycle is in
 *                "Recent Retread / Repair Cycles" (UsedTireInspectionService)
 */
class TireCycleService
{
    public const KINDS = ['RETREAD' => TireRetread::class, 'REPAIR' => TireRepair::class];

    public const PHOTO_MIMES = ['image/jpeg', 'image/png'];

    public const PHOTO_MAX_BYTES = 3 * 1024 * 1024;

    public const MAX_PHOTOS = 3;

    /** Cycle states before completion; RECEIVED is the one the Tire Inspection may start from. */
    public const OPEN = ['SENT', 'RECEIVED', 'FINAL_INSPECTED'];

    public function __construct(
        private readonly TireService $tires,
        private readonly PrivateDocumentStorage $storage,
        private readonly TireInventoryService $inventory,
    ) {}

    /** RETREAD for a tire in RETREAD status, REPAIR for a tire in REPAIR status. */
    public function kindFor(Tire $tire): string
    {
        return match ($tire->current_status) {
            'RETREAD' => 'RETREAD',
            'REPAIR' => 'REPAIR',
            default => throw new TireException("A cycle can be opened only for a tire waiting for retread or repair (this tire is {$tire->current_status})."),
        };
    }

    /**
     * @param  list<UploadedFile>  $photos
     */
    public function open(Tire $tire, string $partnerId, string $estimatedPrice, array $photos, ?string $notes, string $userId): TireRetread|TireRepair
    {
        $kind = $this->kindFor($tire);
        try {
            $price = BigDecimal::of($estimatedPrice);
        } catch (MathException) {
            throw ValidationException::withMessages(['estimated_price' => ['Estimated Price must be a number.']]);
        }
        if ($price->isLessThanOrEqualTo(0) || $price->getScale() > 2) {
            throw ValidationException::withMessages(['estimated_price' => ['Estimated Price must be greater than zero with at most 2 decimals.']]);
        }
        if (count($photos) < 1 || count($photos) > self::MAX_PHOTOS) {
            throw ValidationException::withMessages(['photos' => [Messages::text('validation.tire.uploadPhotoRange', ['max' => self::MAX_PHOTOS])]]);
        }

        $uploads = [];
        foreach (array_values($photos) as $i => $photo) {
            $uploads["photos.{$i}"] = [
                'file' => $photo, 'directory' => "tire-cycles/{$tire->tenant_id}", 'mimes' => self::PHOTO_MIMES,
                'max_bytes' => self::PHOTO_MAX_BYTES, 'label' => 'photo',
            ];
        }

        return $this->storage->persist($uploads, function (array $stored) use ($tire, $kind, $partnerId, $price, $notes, $userId) {
            // The price is stored as an exact decimal string (never a float).
            $cycle = $kind === 'RETREAD'
                ? $this->tires->retread($tire, $partnerId, null, $notes, $userId)
                : $this->tires->repair($tire, $partnerId, null, $notes, $userId);
            $cycle->update(['cost' => (string) $price]);
            foreach ($stored as $file) {
                TireCyclePhoto::query()->create([
                    'tenant_id' => $tire->tenant_id, 'cycle_type' => $kind, 'cycle_id' => $cycle->id, 'disk' => $file['disk'], 'path' => $file['path'],
                    'original_filename' => $file['original_name'], 'mime_type' => $file['mime_type'], 'size' => $file['size'], 'uploaded_by' => $userId,
                ]);
            }

            return $cycle->fresh(['photos', 'partner']);
        });
    }

    /** Receive: the tire is back from the vendor and must be inspected again; it stays in the cycle list. */
    public function receive(TireRetread|TireRepair $cycle, string $userId): TireRetread|TireRepair
    {
        return DB::transaction(function () use ($cycle, $userId) {
            $locked = $cycle::query()->lockForUpdate()->findOrFail($cycle->id);
            if ($locked->status !== 'SENT') {
                throw new TireException("This cycle is {$locked->status}; only a cycle in process can be received.");
            }
            $locked->update(['received_at' => now()->toDateString(), 'received_by' => $userId, 'status' => 'RECEIVED']);

            return $locked->fresh();
        });
    }

    /** The received cycle a Tire Inspection may close (null when the tire has none). */
    public function receivedCycle(string $tireId): TireRetread|TireRepair|null
    {
        foreach (self::KINDS as $class) {
            $cycle = $class::query()->withoutGlobalScopes()->where('tire_id', $tireId)->where('status', 'RECEIVED')->latest('created_at')->first();
            if ($cycle) {
                return $cycle;
            }
        }

        return null;
    }

    /** The cycle a Tire Inspection re-inspected. */
    public function cycleOfInspection(string $inspectionId): TireRetread|TireRepair|null
    {
        foreach (self::KINDS as $class) {
            $cycle = $class::query()->withoutGlobalScopes()->where('tire_used_inspection_id', $inspectionId)->first();
            if ($cycle) {
                return $cycle;
            }
        }

        return null;
    }

    /**
     * "Tires in Retread / Repair Cycle": tires waiting for a cycle (RETREAD / REPAIR) or with an
     * open one, with the open cycle (data scope of the tire index).
     */
    public function openList(string $tenantId, User $user, ?string $search): Collection
    {
        $openTireIds = collect(self::KINDS)->flatMap(fn ($class) => $class::query()->withoutGlobalScopes()->where('tenant_id', $tenantId)
            ->whereIn('status', self::OPEN)->pluck('tire_id'))->unique()->values();
        $query = DB::table('tires')->where('tires.tenant_id', $tenantId)->whereNull('tires.deleted_at')
            ->where(fn ($q) => $q->whereIn('tires.current_status', ['RETREAD', 'REPAIR'])->orWhereIn('tires.id', $openTireIds))
            ->when($search, fn ($q) => $q->where('tires.serial_number', 'ilike', "%{$search}%"))
            ->select('tires.id')->orderBy('tires.serial_number');
        $ids = $this->inventory->scopeToUser($query, $tenantId, $user)->pluck('id');

        $tires = Tire::query()->withoutGlobalScopes()->whereIn('id', $ids)->with('product:id,name,sku')->orderBy('serial_number')->get();
        $cycles = collect(self::KINDS)->flatMap(fn ($class, $kind) => $class::query()->withoutGlobalScopes()->whereIn('tire_id', $ids)->whereIn('status', self::OPEN)
            ->with(['partner:id,name', 'photos'])->get()->map(fn ($c) => ['kind' => $kind, 'cycle' => $c]))->keyBy(fn ($row) => $row['cycle']->tire_id);

        return $tires->map(function (Tire $tire) use ($cycles) {
            $open = $cycles->get($tire->id);

            return [
                'tire' => ['id' => $tire->id, 'serial_number' => $tire->serial_number, 'current_status' => $tire->current_status, 'product' => $tire->product?->only(['id', 'name', 'sku'])],
                'kind' => $open['kind'] ?? ($tire->current_status === 'REPAIR' ? 'REPAIR' : 'RETREAD'),
                'cycle' => $open ? $this->present($open['kind'], $open['cycle']) : null,
            ];
        })->values();
    }

    /** Retread History of a tire: every retread and repair cycle, oldest first. */
    public function history(Tire $tire): array
    {
        return collect(self::KINDS)->flatMap(fn ($class, $kind) => $class::query()->withoutGlobalScopes()->where('tire_id', $tire->id)
            ->with(['partner:id,name', 'photos', 'usedInspection:id,recommendation,recommendation_detail,final_disposition,status,approved_at'])->get()
            ->map(fn ($c) => $this->present($kind, $c)))
            ->sortBy(fn ($row) => [$row['sent_at'], $row['cycle_number']])->values()->all();
    }

    public function present(string $kind, TireRetread|TireRepair $cycle): array
    {
        $inspection = $cycle->relationLoaded('usedInspection') ? $cycle->usedInspection : null;

        return [
            'id' => $cycle->id,
            'kind' => $kind,
            'cycle_number' => $cycle->cycle_number,
            'status' => $cycle->status,
            'state' => match ($cycle->status) {
                'SENT' => 'IN_PROCESS',
                'RECEIVED' => 'RECEIVED',
                'FINAL_INSPECTED' => 'REINSPECTION',
                default => 'COMPLETED',
            },
            'vendor' => $cycle->partner?->only(['id', 'name']),
            'estimated_price' => $cycle->cost,
            'notes' => $cycle->notes,
            'sent_at' => $cycle->sent_at?->toDateString(),
            'received_at' => $cycle->received_at?->toDateString(),
            'inspection' => $inspection ? ['id' => $inspection->id, 'recommendation' => $inspection->recommendation, 'status' => $inspection->status] : ($cycle->tire_used_inspection_id ? ['id' => $cycle->tire_used_inspection_id] : null),
            'inspection_result' => $inspection?->recommendation ?? $cycle->final_inspection_result,
            'final_status' => $cycle->final_status ?? $cycle->approval_disposition,
            'photos' => $cycle->photos->map(fn (TireCyclePhoto $p) => ['id' => $p->id, 'original_filename' => $p->original_filename, 'mime_type' => $p->mime_type])->values()->all(),
        ];
    }
}
