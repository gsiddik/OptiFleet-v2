<?php

namespace App\Domain\Dashboard\Widgets\Tire;

use App\Domain\Dashboard\DashboardContext;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/** TR-03 Tires at Vendor — retread / repair cycles sent and not yet received, oldest first. */
class TiresAtVendorWidget extends TireWidget
{
    public function id(): string
    {
        return 'TR-03';
    }

    public function compute(DashboardContext $context): array
    {
        $retread = $this->cycles($context, 'tire_retreads')->count();
        $repair = $this->cycles($context, 'tire_repairs')->count();
        $items = $this->rows($context)->limit(8)->get()->map(fn ($r) => $this->present($r, $context))->all();

        return ['data' => ['total' => $retread + $repair, 'retread' => $retread, 'repair' => $repair, 'items' => $items]];
    }

    public function detailRules(): ?array
    {
        return ['type' => ['nullable', 'in:RETREAD,REPAIR']];
    }

    public function detail(DashboardContext $context, array $params): array
    {
        return $this->paginate($this->rows($context, $params['type'] ?? null), $params, fn ($r) => $this->present($r, $context));
    }

    private function cycles(DashboardContext $context, string $table): Builder
    {
        $query = DB::table("{$table} as c")->join('tires as t', 't.id', '=', 'c.tire_id')
            ->where('c.tenant_id', $context->tenantId)->where('c.status', 'SENT')->whereNull('c.received_at')->whereNull('t.deleted_at');

        return $this->scopeTires($query, $context);
    }

    private function rows(DashboardContext $context, ?string $type = null): Builder
    {
        $select = fn (string $table, string $kind) => $this->cycles($context, $table)
            ->leftJoin('partners as pa', 'pa.id', '=', 'c.partner_id')
            ->select(['c.id', 'c.tire_id', 't.serial_number', 'c.cycle_number', 'c.sent_at', 'pa.name as vendor_name', DB::raw("'{$kind}' as type")]);

        $query = match ($type) {
            'RETREAD' => $select('tire_retreads', 'RETREAD'),
            'REPAIR' => $select('tire_repairs', 'REPAIR'),
            default => $select('tire_retreads', 'RETREAD')->unionAll($select('tire_repairs', 'REPAIR')),
        };

        return DB::query()->fromSub($query, 'x')->orderBy('x.sent_at')->orderBy('x.serial_number');
    }

    private function present(object $r, DashboardContext $context): array
    {
        return [
            'id' => $r->id, 'type' => $r->type, 'tire_id' => $r->tire_id, 'serial_number' => $r->serial_number, 'cycle_number' => (int) $r->cycle_number,
            'vendor_name' => $r->vendor_name, 'sent_at' => $r->sent_at, 'days_at_vendor' => self::daysSince($context, $r->sent_at),
        ];
    }
}
