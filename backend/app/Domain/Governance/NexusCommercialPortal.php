<?php

namespace App\Domain\Governance;

use App\Domain\Governance\Exceptions\GovernanceContractException;
use App\Support\TenantContext;
use Illuminate\Http\UploadedFile;

/** No local financial mirrors: every central read/write is bound to the current tenant and signed-in Nexus actor. */
class NexusCommercialPortal
{
    public function __construct(private readonly CentralGovernance $central, private readonly NexusApiClient $api, private readonly TenantContext $context) {}

    public function active(): bool
    {
        return $this->central->governs($this->context->tenantId());
    }

    public function request(string $method, string $path, array $data = []): array
    {
        $link = $this->central->link($this->context->tenantId());
        $tenant = $link['nexus_tenant_id'] ?? null;
        $actor = request()->user()?->optinexus_subject;
        if (! $tenant || ! $actor) throw new GovernanceContractException('Missing central tenant or actor.');
        $scopes = $method === 'GET' ? NexusTokenProvider::SCOPES_GOVERNANCE : 'commercial.read commercial.payment.submit';
        $r = $this->api->send($scopes, $method, 'commercial-portal/tenants/'.$tenant.'/'.$path,
            [...$data, 'acting_user_id' => $actor]);
        if ($r['status'] === 422) {
            throw \Illuminate\Validation\ValidationException::withMessages(['invoice_id' => [$r['error']['message'] ?? 'Payment submission refused.']]);
        }
        if (in_array($r['status'], [403, 404, 409], true)) abort($r['status'], $r['error']['message'] ?? 'Commercial request refused.');
        if ($r['status'] < 200 || $r['status'] >= 300 || ! is_array($r['data'])) throw new GovernanceContractException('Invalid commercial portal response.');
        // Reject any cross-tenant document in lists or details.
        $rows = array_is_list($r['data']) ? $r['data'] : [$r['data']];
        foreach ($rows as $row) {
            if (! is_array($row) || ($row['tenant_id'] ?? null) !== $tenant) throw new GovernanceContractException('Commercial document tenant mismatch.');
        }
        if ($method === 'GET' && preg_match('~^(invoices|payments)/([^/]+)$~D', $path, $match) && ($r['data']['id'] ?? null) !== $match[2]) {
            throw new GovernanceContractException('Commercial document identifier mismatch.');
        }
        if ($method === 'POST' && $path === 'payments' && ($r['data']['invoice_id'] ?? null) !== ($data['invoice_id'] ?? null)) {
            throw new GovernanceContractException('Payment invoice mismatch.');
        }
        return $r;
    }

    public function listing(string $resource, array $query = [])
    {
        if ($resource === 'invoices' && ($query['status'] ?? '') === 'OUTSTANDING') $query['status'] = 'ISSUED';
        $r = $this->request('GET', $resource, $query);
        return response()->json(['data' => array_map(fn ($row) => $resource === 'invoices' ? $this->invoice($row) : $this->payment($row), $r['data']), 'meta' => $r['meta']['pagination'] ?? $r['meta']]);
    }

    public function invoice(array $row): array
    {
        return [...$row, 'tenant_id' => $this->context->tenantId(), 'source' => 'OPTINEXUS',
            'status' => $row['status'],
            'invoice_date' => $row['issue_date'] ?? $row['created_at'], 'outstanding_amount' => $row['balance_due'],
            'items' => array_map(fn ($i) => [...$i, 'unit_price' => $i['unit_amount'], 'amount' => $i['total']], $row['items'] ?? [])];
    }

    public function payment(array $row): array
    {
        return [...$row, 'tenant_id' => $this->context->tenantId(), 'source' => 'OPTINEXUS',
            'payment_date' => $row['paid_at'], 'payment_method' => $row['method'], 'transaction_reference' => $row['reference'],
            'invoice' => ['id' => $row['invoice_id'], 'invoice_number' => $row['invoice_number']],
            'proofs' => empty($row['proof']) ? [] : [$row['proof']]];
    }

    public function proofData(?UploadedFile $file): array
    {
        return $file ? ['proof' => ['filename' => $file->getClientOriginalName(), 'content' => base64_encode($file->getContent())]] : [];
    }

    public function download(string $payment, string $proof)
    {
        $row = $this->request('GET', 'payments/'.$payment.'/proof')['data'];
        abort_unless(($row['id'] ?? null) === $proof && ($row['payment_id'] ?? null) === $payment, 404);
        $bytes = base64_decode($row['content'], true);
        if ($bytes === false) throw new GovernanceContractException('Invalid proof content.');
        return response($bytes, 200, ['Content-Type' => $row['mime_type'],
            'Content-Disposition' => \Symfony\Component\HttpFoundation\HeaderUtils::makeDisposition('attachment', $row['filename'], 'proof'),
            'X-Content-Type-Options' => 'nosniff']);
    }
}
