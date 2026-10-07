<?php

namespace Tests\Feature\Dashboard;

use App\Domain\ProductMaster\Models\Product;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Data completeness contract of the dashboard: a value that is missing is "unavailable" (null + a reason),
 * never 0; records without complete work-time history never enter an average; partial totals say so; the
 * date basis and the coverage of every affected widget are in its payload; payment records that break the
 * payment contract are reported, not hidden or double counted; stock without a valuation is apart from the total.
 */
class DashboardDataCompletenessTest extends TestCase
{
    use DashboardTestHelpers;

    private array $s = [];

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function base(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-20 12:00:00', 'UTC'));
        $tenant = $this->makeTenant(['code' => 'CMP-'.Str::random(4), 'timezone' => 'Asia/Jakarta']);
        $this->grantModules($tenant, ['VEHICLE', 'WORK_ORDER', 'WORKSHOP', 'INVENTORY', 'TIRE', 'PROCUREMENT']);
        $branch = $this->makeBranch($tenant);
        $workshop = $this->makeWorkshop($tenant, $branch);
        $warehouse = $this->makeWarehouse($tenant, $branch);
        $category = $this->makeVehicleCategory();
        $this->s = compact('tenant', 'branch', 'workshop', 'warehouse') + [
            'complete' => $this->makeVehicle($tenant, $branch, $category, ['registration_number' => 'B 1 CMP']),
            'legacy' => $this->makeVehicle($tenant, $branch, $category, ['registration_number' => 'B 2 CMP']),
            'idle' => $this->makeVehicle($tenant, $branch, $category, ['registration_number' => 'B 3 CMP']),
            'mechanic' => $this->makeWorker($tenant, $branch, $workshop, ['employee_code' => 'CMP-M', 'hourly_rate' => '50000.00']),
        ];
    }


    /** Every code the API emits in a basis / limitation has English and Indonesian text in the frontend resources. */
    private function assertTranslated(array $envelope): void
    {
        $dictionaries = [];
        foreach (['en', 'id'] as $locale) {
            $dictionaries[$locale] = json_decode((string) file_get_contents(base_path("../frontend/src/i18n/locales/{$locale}/dashboard.json")), true, 512, JSON_THROW_ON_ERROR);
        }
        $has = function (array $dictionary, string $path): bool {
            $node = $dictionary;
            foreach (explode('.', $path) as $part) {
                if (! is_array($node) || ! array_key_exists($part, $node)) {
                    return false;
                }
                $node = $node[$part];
            }

            return is_string($node);
        };
        $paths = [];
        $basis = $envelope['basis'] ?? null;
        foreach ($basis['date_basis'] ?? [] as $d) {
            $paths[] = 'basis.date.'.$d['code'];
        }
        foreach (array_merge($basis['includes'] ?? [], $basis['excludes'] ?? []) as $code) {
            $paths[] = 'basis.item.'.$code;
        }
        foreach ($basis['completeness']['reasons'] ?? [] as $reason) {
            $paths[] = 'basis.reason.'.$reason['code'].'_other';
        }
        foreach ($envelope['limitations'] ?? [] as $limitation) {
            $paths[] = preg_replace('/^dashboard\./', '', $limitation['code']);
        }
        foreach (['en', 'id'] as $locale) {
            foreach ($paths as $path) {
                $this->assertTrue($has($dictionaries[$locale], $path), "missing {$locale} text for dashboard.{$path}");
            }
        }
        $this->assertNotEmpty($paths);
    }

    private function interval(string $woId, string $start, ?string $end, int $cycle = 1): void
    {
        DB::table('work_order_work_intervals')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $this->s['tenant']->id, 'work_order_id' => $woId, 'cycle' => $cycle,
            'started_at' => $start, 'ended_at' => $end, 'start_from_status' => 'SCHEDULED', 'end_to_status' => $end ? 'QC_PENDING' : null, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function assign(string $woId, string $from = '2026-09-01 00:00:00'): void
    {
        DB::table('work_order_mechanic_assignments')->insert(['id' => (string) Str::uuid(), 'work_order_id' => $woId, 'worker_id' => $this->s['mechanic']->id,
            'role' => 'PRIMARY', 'hourly_rate_snapshot' => '50000.0000', 'assigned_at' => $from, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function consume(string $woId, string $productId, string $at, string $total = '200000.0000'): void
    {
        $part = (string) Str::uuid();
        DB::table('work_order_planned_parts')->insert(['id' => $part, 'tenant_id' => $this->s['tenant']->id, 'work_order_id' => $woId, 'product_id' => $productId,
            'warehouse_id' => $this->s['warehouse']->id, 'description' => 'x', 'planned_quantity' => 2, 'issued_quantity' => 2, 'consumed_quantity' => 2, 'total_cost' => $total,
            'stock_condition' => 'NEW', 'status' => 'CONSUMED', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('stock_movements')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $this->s['tenant']->id, 'warehouse_id' => $this->s['warehouse']->id, 'product_id' => $productId,
            'movement_type' => 'CONSUME', 'quantity' => 2, 'unit_cost' => '1.0000', 'reference_type' => \App\Domain\WorkOrder\Models\WorkOrderPlannedPart::class,
            'reference_id' => $part, 'occurred_at' => $at, 'created_at' => now(), 'updated_at' => now()]);
    }

    /** B 1: complete history (4 h, valued). B 2: started before intervals existed (no history) with parts only. B 3: nothing. */
    private function costScenario(): void
    {
        $this->base();
        ['tenant' => $t, 'branch' => $b, 'workshop' => $ws] = $this->s;
        $product = $this->makeProduct($t, null, null, ['name' => 'Filter']);
        $good = $this->makeWorkOrder($t, $b, $ws, $this->s['complete'], ['status' => 'COMPLETED', 'started_at' => '2026-09-05 01:00:00', 'completed_at' => '2026-09-06 03:00:00']);
        $this->interval($good->id, '2026-09-05 01:00:00', '2026-09-05 05:00:00');
        $this->assign($good->id);
        $this->consume($good->id, $product->id, '2026-09-05 03:00:00');
        $legacy = $this->makeWorkOrder($t, $b, $ws, $this->s['legacy'], ['status' => 'COMPLETED', 'started_at' => '2026-09-07 01:00:00', 'completed_at' => '2026-09-08 03:00:00']);
        $this->assign($legacy->id);
        $this->consume($legacy->id, $product->id, '2026-09-07 03:00:00', '300000.0000');
        $this->s += compact('good', 'legacy');
    }

    public function test_most_costly_vehicle_marks_unavailable_labor_instead_of_zero_and_reports_completeness(): void
    {
        $this->costScenario();
        [, $token] = $this->makeTenantUser($this->s['tenant'], ['dashboard.finance.view']);
        $res = $this->widget($token, 'FN-07', ['months' => 3])->assertOk()->json('data');
        $rows = collect($res['data']['vehicles'])->keyBy('registration_number');

        $this->assertSame(['AVAILABLE', '200000.00'], [$rows['B 1 CMP']['labor_status'], $rows['B 1 CMP']['PARTS']]);
        $this->assertSame('200000.00', $rows['B 1 CMP']['LABOR']);
        $this->assertTrue($rows['B 1 CMP']['cost_complete']);

        $this->assertSame('UNAVAILABLE', $rows['B 2 CMP']['labor_status']);
        $this->assertNull($rows['B 2 CMP']['LABOR'], 'no recorded work time: unavailable, not 0');
        $this->assertSame('300000.00', $rows['B 2 CMP']['total'], 'the total is what was recorded');
        $this->assertFalse($rows['B 2 CMP']['cost_complete']);

        $this->assertSame(['NONE', '0.00', true], [$rows['B 3 CMP']['labor_status'], $rows['B 3 CMP']['LABOR'], $rows['B 3 CMP']['cost_complete']], 'a vehicle with no started Work Order has a true zero');

        $this->assertTranslated($res);
        $completeness = $res['basis']['completeness'];
        $this->assertSame(['PARTIAL', 2, 1, 1], [$completeness['status'], $completeness['total'], $completeness['valid'], $completeness['excluded']]);
        $this->assertContains(['code' => 'WORK_TIME_UNAVAILABLE', 'n' => 1], $completeness['reasons']);
        $this->assertSame(1, $res['data']['vehicles_cost_incomplete']);
        $this->assertContains('dashboard.limitations.workTimeUnavailable', array_column($res['limitations'], 'code'));
        $this->assertSame('2026-09-05', $res['basis']['history_from']);
        $this->assertContains(['key' => 'EXTERNAL_PAID', 'code' => 'PAYMENT_DATE'], $res['basis']['date_basis']);
        $this->assertContains(['key' => 'PARTS', 'code' => 'CONSUMPTION_DATE'], $res['basis']['date_basis']);
        $this->assertContains('ESTIMATES', $res['basis']['excludes']);

        // Drill-down: the Work Order without history says so, with a null labor figure.
        $wos = collect($this->details($token, 'FN-07', ['months' => 3, 'vehicle_id' => $this->s['legacy']->id])->assertOk()->json('data.data'));
        $this->assertSame(['UNAVAILABLE', null, 'UNAVAILABLE', false], [$wos[0]['labor_status'], $wos[0]['LABOR'], $wos[0]['work_time_state'], $wos[0]['history_complete']]);

        // Cost mix carries the same completeness and basis.
        $mix = $this->widget($token, 'FN-08', ['months' => 3])->assertOk()->json('data');
        $this->assertSame('PARTIAL', $mix['basis']['completeness']['status']);
        $this->assertSame($res['data']['totals'], $mix['data']['totals']);
    }

    public function test_mechanic_performance_never_averages_work_orders_without_history_and_reports_the_exclusions(): void
    {
        $this->base();
        ['tenant' => $t, 'branch' => $b, 'workshop' => $ws, 'mechanic' => $m] = $this->s;
        // 2 complete, 1 without any interval (unavailable), 1 whose first interval starts late (partial) — all CORRECTIVE, completed in September.
        foreach ([['2026-09-02 01:00:00', 4, true], ['2026-09-03 01:00:00', 2, true], ['2026-09-04 01:00:00', 0, false], ['2026-09-05 01:00:00', 3, 'late']] as [$start, $hours, $kind]) {
            $wo = $this->makeWorkOrder($t, $b, $ws, $this->s['complete'], ['status' => 'COMPLETED', 'maintenance_type' => 'CORRECTIVE', 'started_at' => $start, 'completed_at' => date('Y-m-d H:i:s', strtotime($start) + 86400)]);
            $this->assign($wo->id, '2026-09-01 00:00:00');
            if ($kind === true) {
                $this->interval($wo->id, $start, date('Y-m-d H:i:s', strtotime($start) + $hours * 3600));
            } elseif ($kind === 'late') {
                $this->interval($wo->id, date('Y-m-d H:i:s', strtotime($start) + 7200), date('Y-m-d H:i:s', strtotime($start) + 7200 + $hours * 3600));
            }
        }
        [, $token] = $this->makeTenantUser($t, ['work_order.view', 'worker.view']);
        $res = $this->widget($token, 'WS-07', ['months' => 3, 'maintenance_type' => 'CORRECTIVE'])->assertOk()->json('data');
        $row = $res['data']['mechanics'][0];

        $this->assertSame([2, 4, 2], [$row['valid_samples'], $row['completed_total'], $row['excluded_samples']]);
        $this->assertSame(['WORK_TIME_UNAVAILABLE' => 1, 'WORK_TIME_PARTIAL' => 1, 'NO_ATTRIBUTED_TIME' => 0], $row['excluded_reasons']);
        $this->assertSame('3.00', $row['avg_hours'], 'only the two complete Work Orders (4 h and 2 h) are averaged');
        $this->assertSame('AVAILABLE', $row['avg_state']);
        $this->assertTranslated($res);
        $completeness = $res['basis']['completeness'];
        $this->assertSame(['PARTIAL', 4, 2, 2], [$completeness['status'], $completeness['total'], $completeness['valid'], $completeness['excluded']]);
        $this->assertSame($completeness, $res['data']['completeness']);
        $this->assertSame('2026-09-02', $res['basis']['history_from']);

        // A mechanic whose every Work Order lacks history: no average (null / UNAVAILABLE), not 0.
        $other = $this->makeWorker($t, $b, $ws, ['employee_code' => 'CMP-N']);
        $wo = $this->makeWorkOrder($t, $b, $ws, $this->s['legacy'], ['status' => 'COMPLETED', 'maintenance_type' => 'PREVENTIVE', 'started_at' => '2026-09-09 01:00:00', 'completed_at' => '2026-09-10 01:00:00']);
        DB::table('work_order_mechanic_assignments')->insert(['id' => (string) Str::uuid(), 'work_order_id' => $wo->id, 'worker_id' => $other->id, 'role' => 'PRIMARY',
            'hourly_rate_snapshot' => '1.0000', 'assigned_at' => '2026-09-01 00:00:00', 'created_at' => now(), 'updated_at' => now()]);
        $prev = $this->widget($token, 'WS-07', ['months' => 3, 'maintenance_type' => 'PREVENTIVE'])->assertOk()->json('data');
        $none = $prev['data']['mechanics'][0];
        $this->assertSame([0, 1, null, 'UNAVAILABLE'], [$none['valid_samples'], $none['completed_total'], $none['avg_hours'], $none['avg_state']]);
        $this->assertSame('UNAVAILABLE', $prev['basis']['completeness']['status']);
    }

    public function test_payment_records_outside_the_contract_are_reported_and_never_double_counted(): void
    {
        $this->costScenario();
        ['tenant' => $t, 'branch' => $b, 'workshop' => $ws] = $this->s;
        [$user, $token] = $this->makeTenantUser($t, ['dashboard.finance.view']);
        $partner = $this->makePartner($t);
        $invoice = function (string $number, string $total, string $status, string $currency = 'IDR') use ($t, $partner) {
            $memo = (string) Str::uuid();
            DB::table('work_order_external_services')->insert(['id' => $memo, 'tenant_id' => $t->id, 'work_order_id' => $this->s['good']->id, 'partner_id' => $partner->id, 'description' => 'm', 'created_at' => now(), 'updated_at' => now()]);
            $id = (string) Str::uuid();
            DB::table('workshop_invoices')->insert(['id' => $id, 'tenant_id' => $t->id, 'work_order_external_service_id' => $memo, 'work_order_id' => $this->s['good']->id, 'partner_id' => $partner->id,
                'external_invoice_number' => $number, 'external_invoice_number_normalized' => strtolower($number), 'invoice_date' => '2026-09-08', 'total_amount' => $total, 'status' => $status,
                'currency' => $currency, 'created_at' => now(), 'updated_at' => now()]);

            return $id;
        };
        $pay = fn (string $invoiceId, string $amount) => DB::table('workshop_invoice_payments')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $t->id, 'workshop_invoice_id' => $invoiceId,
            'payment_date' => '2026-09-12', 'paid_amount' => $amount, 'evidence_url' => 'x.pdf', 'uploaded_by' => $user->id, 'uploaded_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $pay($invoice('SI-OK', '500000.00', 'RECORDED'), '500000.00');                       // a valid full payment
        $pay($invoice('SI-PART', '800000.00', 'RECORDED'), '300000.00');                     // does not match the invoice: partial
        $pay($invoice('SI-CANC', '400000.00', 'CANCELLED'), '400000.00');                   // cancelled but paid: not counted, reported
        $pay($invoice('SI-USD', '100.00', 'RECORDED', 'USD'), '100.00');                      // another currency: not added, reported
        $invoice('SI-UNPAID', '999999.00', 'RECORDED');                                      // an invoice is not a payment
        $wo = $this->makeWorkOrder($t, $b, $ws, $this->s['legacy'], ['status' => 'CLOSED', 'execution_mode' => 'EXTERNAL']);
        DB::table('work_order_external_invoices')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $t->id, 'branch_id' => $b->id, 'work_order_id' => $wo->id, 'status' => 'PAID',
            'wal_number' => 'WAL-X', 'vendor_invoice_date' => '2026-09-01', 'vendor_invoice_amount' => '2000000.00', 'payment_date' => '2026-09-10', 'paid_amount' => '1500000.00',
            'created_at' => now(), 'updated_at' => now()]);

        $res = $this->widget($token, 'FN-07', ['months' => 3])->assertOk()->json('data');

        // Counted: the matching service payment (500000) and the external payment actually made (1500000) — the amounts paid, once each.
        $this->assertSame(2000000.0 + 300000.0, (float) $res['data']['totals']['EXTERNAL_PAID'], 'valid payment + partial payment actually made + external paid amount; cancelled / foreign-currency / unpaid excluded');
        $this->assertTranslated($res);
        $this->assertSame(4, $res['data']['payment_anomalies']);
        $this->assertContains('dashboard.limitations.paymentAnomalies', array_column($res['limitations'], 'code'));
        $anomalies = collect($this->details($token, 'FN-07', ['months' => 3, 'view' => 'payment_anomalies'])->assertOk()->json('data.data'));
        $this->assertEqualsCanonicalizing(['EXTERNAL_PARTIAL', 'SERVICE_PARTIAL', 'SERVICE_CANCELLED_PAID', 'NON_BASE_CURRENCY'], $anomalies->pluck('kind')->all());
        $this->assertSame(['1500000.00', '2000000.00'], [$anomalies->firstWhere('kind', 'EXTERNAL_PARTIAL')['paid'], $anomalies->firstWhere('kind', 'EXTERNAL_PARTIAL')['expected']]);
        $this->assertSame($res['data']['payment_anomalies'], $this->widget($token, 'FN-08', ['months' => 3])->json('data.data.payment_anomalies'));

        // Financial detail never goes to a user without the finance permission.
        [, $plain] = $this->makeTenantUser($t, ['work_order.view']);
        $this->widget($plain, 'FN-07', ['months' => 3])->assertStatus(403);
        $this->details($plain, 'FN-07', ['months' => 3, 'view' => 'payment_anomalies'])->assertStatus(403);
    }

    public function test_inventory_value_separates_recorded_valuation_from_stock_pending_valuation(): void
    {
        $this->base();
        ['tenant' => $t, 'warehouse' => $wh] = $this->s;
        $pcs = $this->makeUom(['code' => 'PCS-'.Str::random(3)]);
        $ltr = $this->makeUom(['code' => 'LTR-'.Str::random(3)]);
        $stock = function (Product $p, string $qty, string $cost) use ($t, $wh) {
            DB::table('warehouse_stocks')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $t->id, 'warehouse_id' => $wh->id, 'product_id' => $p->id, 'quantity_on_hand' => $qty,
                'average_unit_cost' => $cost, 'created_at' => now(), 'updated_at' => now()]);
        };
        $valued = $this->makeProduct($t, null, $pcs, ['name' => 'Valued', 'product_type' => 'SPARE_PART']);
        $nocost = $this->makeProduct($t, null, $ltr, ['name' => 'No cost oil', 'product_type' => 'CONSUMABLE']);
        $tire = $this->makeProduct($t, null, $pcs, ['name' => 'Tire', 'product_type' => 'TIRE']);
        $stock($valued, '4', '1000.0000');
        $stock($nocost, '7', '0.0000');
        $stock($tire, '2', '5000.0000');
        DB::table('used_tire_stocks')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $t->id, 'warehouse_id' => $wh->id, 'product_id' => $tire->id, 'quantity_on_hand' => 3, 'created_at' => now(), 'updated_at' => now()]);

        [, $token] = $this->makeTenantUser($t, ['inventory.view', 'dashboard.finance.view']);
        $res = $this->widget($token, 'FN-05')->assertOk()->json('data');
        $data = $res['data'];

        $this->assertTranslated($res);
        $this->assertSame('14000.00', $data['total'], 'recorded valuation only: 4 × 1000 + 2 × 5000');
        $this->assertSame(2, $data['valued_skus']);
        $this->assertSame(['skus' => 2, 'used_skus' => 1, 'no_cost_skus' => 1], array_intersect_key($data['pending_valuation'], array_flip(['skus', 'used_skus', 'no_cost_skus'])));
        $pending = collect($data['pending_valuation']['quantities']);
        $this->assertSame('3.0000', $pending->firstWhere('reason', 'USED_STOCK')['quantity']);
        $this->assertSame([$ltr->code, '7.0000'], [$pending->firstWhere('reason', 'NO_UNIT_COST')['uom'], $pending->firstWhere('reason', 'NO_UNIT_COST')['quantity']], 'quantities stay per UOM');
        $this->assertSame('PARTIAL', $res['basis']['completeness']['status']);
        $this->assertSame([4, 2, 2], [$res['basis']['completeness']['total'], $res['basis']['completeness']['valid'], $res['basis']['completeness']['excluded']]);
        $this->assertContains('dashboard.limitations.valuationPending', array_column($res['limitations'], 'code'));
        $this->assertContains('IN_TRANSIT', $res['basis']['excludes']);

        // Drill-down: the valued rows add up to the total; the pending rows carry quantity but never a value.
        $valuedRows = collect($this->details($token, 'FN-05')->assertOk()->json('data.data'));
        $this->assertSame($data['total'], number_format($valuedRows->sum(fn ($r) => (float) $r['value']), 2, '.', ''));
        $pendingRows = collect($this->details($token, 'FN-05', ['view' => 'pending_valuation'])->assertOk()->json('data.data'));
        $this->assertSame(['NO_UNIT_COST', 'USED_STOCK'], $pendingRows->pluck('reason')->sort()->values()->all());
        $this->assertSame([null, null], $pendingRows->pluck('value')->all());

        [, $noFinance] = $this->makeTenantUser($t, ['inventory.view']);
        $this->widget($noFinance, 'FN-05')->assertStatus(403);
        $this->details($noFinance, 'FN-05', ['view' => 'pending_valuation'])->assertStatus(403);
    }

    public function test_installed_components_report_untracked_consumed_parts_and_never_count_them_as_installed(): void
    {
        $this->base();
        ['tenant' => $t, 'branch' => $b, 'workshop' => $ws, 'warehouse' => $wh, 'complete' => $v] = $this->s;
        $pcs = $this->makeUom(['code' => 'PCS-'.Str::random(3)]);
        $filter = $this->makeProduct($t, null, $pcs, ['name' => 'Oil filter', 'product_type' => 'SPARE_PART', 'track_serial_number' => false]);
        $wo = $this->makeWorkOrder($t, $b, $ws, $v, ['status' => 'COMPLETED']);
        $this->consume($wo->id, $filter->id, '2026-09-05 03:00:00');
        $tireProduct = $this->makeProduct($t, null, $pcs, ['name' => 'Tire', 'product_type' => 'TIRE']);
        $tire = (string) Str::uuid();
        DB::table('tires')->insert(['id' => $tire, 'tenant_id' => $t->id, 'serial_number' => 'T-1', 'product_id' => $tireProduct->id, 'current_status' => 'INSTALLED', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('tire_installations')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $t->id, 'tire_id' => $tire, 'vehicle_id' => $v->id, 'wheel_position' => 'FL', 'installed_at' => '2026-08-01 00:00:00',
            'installation_source' => 'INITIAL_REGISTRATION', 'created_at' => now(), 'updated_at' => now()]);

        [, $token] = $this->makeTenantUser($t, ['vehicle.view', 'tire.view', 'dashboard.finance.view']);
        $res = $this->widget($token, 'FL-07')->assertOk()->json('data');

        $this->assertTranslated($res);
        $this->assertSame(1, $res['data']['totals']['items'], 'only the serial tire is installed; the consumed filter is not');
        $this->assertSame(['lines' => 1, 'products' => 1], array_intersect_key($res['data']['untracked'], array_flip(['lines', 'products'])));
        $this->assertSame([['uom' => $pcs->code, 'quantity' => '2.0000']], $res['data']['untracked']['quantities']);
        $this->assertContains('NON_SERIAL_PARTS_CONSUMED', $res['basis']['excludes']);
        $this->assertContains(['key' => 'INSTALLED', 'code' => 'INSTALLATION_DATE'], $res['basis']['date_basis']);
        $this->assertSame(['UNAVAILABLE', 1, 0], [$res['basis']['completeness']['status'], $res['basis']['completeness']['total'], $res['basis']['completeness']['valid']], 'the tire has no cost basis: unavailable, not 0');
        $this->assertNull($res['data']['totals']['value'], 'no item has a cost basis: the installed value is unavailable, not a valid zero');
        $this->assertNull($res['data']['vehicles'][0]['value']);
    }

    public function test_procurement_cycle_states_that_it_counts_from_pr_creation_not_approval(): void
    {
        $this->base();
        ['tenant' => $t, 'warehouse' => $wh] = $this->s;
        $partner = $this->makePartner($t);
        $pr = (string) Str::uuid();
        DB::table('purchase_requests')->insert(['id' => $pr, 'tenant_id' => $t->id, 'pr_number' => 'PR-1', 'warehouse_id' => $wh->id, 'status' => 'APPROVED', 'created_at' => '2026-09-01 03:00:00', 'updated_at' => now()]);
        foreach ([['PO-1', $pr], ['PO-2', null]] as $i => [$number, $request]) {
            $po = (string) Str::uuid();
            DB::table('purchase_orders')->insert(['id' => $po, 'tenant_id' => $t->id, 'po_number' => $number, 'partner_id' => $partner->id, 'purchase_request_id' => $request, 'delivery_warehouse_id' => $wh->id,
                'status' => 'RECEIVED', 'order_date' => '2026-09-04', 'created_at' => now(), 'updated_at' => now()]);
            DB::table('goods_receipts')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $t->id, 'gr_number' => 'GR-'.$i, 'purchase_order_id' => $po, 'warehouse_id' => $wh->id,
                'partner_id' => $partner->id, 'status' => 'POSTED', 'received_at' => '2026-09-10 03:00:00', 'created_at' => now(), 'updated_at' => now()]);
        }
        [, $buyer] = $this->makeTenantUser($t, ['purchase_order.view']);
        $res = $this->widget($buyer, 'PR-05', ['months' => 3])->assertOk()->json('data');

        $this->assertTranslated($res);
        $this->assertContains(['key' => 'PR_TO_PO', 'code' => 'PR_CREATED_TO_PO_ORDER_DATE'], $res['basis']['date_basis']);
        $this->assertContains('PR_APPROVAL_DATE_NOT_RECORDED', $res['basis']['excludes']);
        $this->assertSame(['PARTIAL', 2, 1], [$res['basis']['completeness']['status'], $res['basis']['completeness']['total'], $res['basis']['completeness']['valid']]);
        $this->assertContains(['code' => 'NO_PURCHASE_REQUEST', 'n' => 1], $res['basis']['completeness']['reasons']);
        // The Work-Order-free user is not told about money; and without the permission the widget is not served.
        [, $none] = $this->makeTenantUser($t, ['vehicle.view']);
        $this->widget($none, 'PR-05', ['months' => 3])->assertStatus(403);
    }
}
