<?php

namespace Tests\Feature\Governance;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Concerns\InteractsWithCentralGovernance;
use Tests\TestCase;

class CentralInvoicePaymentTest extends TestCase
{
    use InteractsWithCentralGovernance;

    public function test_central_invoice_and_payment_detail_use_authoritative_nexus_data_without_local_records(): void
    {
        $this->bootCentralGovernance(); $tenant = $this->makeCentralTenant();
        [$user, $token] = $this->makeCentralUser($tenant, ['account.invoice.view', 'account.payment.view', 'account.payment.submit']);
        $invoice = (string) Str::uuid(); $payment = (string) Str::uuid(); $status = 'SUBMITTED';
        $this->nexus->commercialPortal = function ($request, $path, $query, $body) use ($tenant, $user, $invoice, $payment, &$status) {
            $this->assertStringContainsString('/tenants/'.$tenant->optinexus_tenant_id.'/', $path);
            $this->assertSame($user->optinexus_subject, ($query + $body)['acting_user_id']);
            $row = ['id' => $invoice, 'tenant_id' => $tenant->optinexus_tenant_id, 'status' => 'ISSUED', 'invoice_number' => 'NEXUS-1',
                'issue_date' => '2026-10-22', 'balance_due' => '1500000.00', 'total' => '1500000.00', 'currency' => 'IDR', 'created_at' => '2026-10-22', 'items' => []];
            if (str_contains($path, '/payments')) {
                $row = ['id' => $payment, 'tenant_id' => $tenant->optinexus_tenant_id, 'invoice_id' => $invoice, 'invoice_number' => 'NEXUS-1',
                    'status' => $status, 'paid_at' => '2026-10-22', 'amount' => '1500000.00', 'method' => 'BANK_TRANSFER', 'reference' => 'REF', 'proof' => null];
                if ($request->method() === 'POST') $this->assertSame($invoice, $body['invoice_id']);
            }
            return Http::response(['success' => true, 'data' => str_ends_with($path, '/invoices') && $request->method() === 'GET' ? [$row] : $row, 'meta' => ['pagination' => ['total' => 1]]], $request->method() === 'POST' ? 201 : 200);
        };
        $headers = $this->authHeaders($token);
        $this->getJson('/api/v1/app/account/invoices', $headers)->assertOk()->assertJsonPath('data.0.invoice_number', 'NEXUS-1');
        $this->getJson('/api/v1/app/account/invoices/'.$invoice, $headers)->assertOk()->assertJsonPath('data.outstanding_amount', '1500000.00');
        $this->postJson('/api/v1/app/account/payments', ['invoice_id' => $invoice, 'submission_key' => (string) Str::uuid(),
            'amount' => '1500000.00', 'payment_date' => '2026-10-22', 'payment_method' => 'BANK_TRANSFER'], $headers)->assertCreated()->assertJsonPath('data.status', 'SUBMITTED');
        $status = 'REJECTED';
        $this->getJson('/api/v1/app/account/payments/'.$payment, $headers)->assertOk()->assertJsonPath('data.status', 'REJECTED');
        $status = 'VERIFIED';
        $this->getJson('/api/v1/app/account/payments/'.$payment, $headers)->assertOk()->assertJsonPath('data.status', 'VERIFIED');
        $this->assertDatabaseCount('invoices', 0); $this->assertDatabaseCount('payments', 0);
    }

    public function test_central_response_from_another_tenant_is_refused_and_permission_denial_does_not_submit(): void
    {
        $this->bootCentralGovernance(); $tenant = $this->makeCentralTenant();
        [, $token] = $this->makeCentralUser($tenant, ['account.invoice.view']);
        $this->nexus->commercialPortal = fn () => Http::response(['data' => [['id' => (string) Str::uuid(), 'tenant_id' => (string) Str::uuid()]]]);
        $this->getJson('/api/v1/app/account/invoices', $this->authHeaders($token))->assertStatus(503);
        $before = $this->nexus->callsTo('commercial-portal');
        $this->postJson('/api/v1/app/account/payments', ['invoice_id' => (string) Str::uuid()], $this->authHeaders($token))->assertForbidden();
        $this->assertSame($before, $this->nexus->callsTo('commercial-portal'));
    }
}
