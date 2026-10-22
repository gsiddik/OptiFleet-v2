<?php

namespace App\Http\Controllers\Api\Tenant\Account;

use App\Domain\Invoice\Models\Invoice;
use App\Domain\Payment\Models\Payment;
use App\Domain\Payment\Models\PaymentProof;
use App\Domain\Payment\Services\PaymentSubmissionService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\SubmitPaymentRequest;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class AccountPaymentController extends Controller
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly PaymentSubmissionService $submission,
        private readonly \App\Domain\Governance\NexusCommercialPortal $portal,
    ) {}

    public function index(Request $request)
    {
        if ($this->portal->active()) return $this->portal->listing('payments', $request->only(['status', 'per_page', 'page']));

        $query = Payment::query()->where('tenant_id', $this->context->tenantId())->with(['proofs', 'invoice']);

        if ($status = $request->string('status')->value()) {
            $query->where('status', $status);
        }

        return $this->paginated($query->latest('created_at')->paginate($request->integer('per_page', 15)));
    }

    public function show(string $payment)
    {
        if ($this->portal->active()) return $this->ok($this->portal->payment($this->portal->request('GET', 'payments/'.$payment)['data']));
        $payment = Payment::findOrFail($payment);
        $this->authorizeOwnership($payment);

        return $this->ok($payment->load(['proofs', 'invoice']));
    }

    public function store(SubmitPaymentRequest $request)
    {
        if ($this->portal->active()) {
            $data = $request->safe()->except(['file']);
            $row = $this->portal->request('POST', 'payments', [...$data, ...$this->portal->proofData($request->file('file'))])['data'];
            return $this->ok($this->portal->payment($row), 201);
        }
        $invoice = Invoice::query()->where('tenant_id', $this->context->tenantId())->findOrFail($request->input('invoice_id'));

        $payment = $this->submission->submit($invoice, $request->safe()->except(['invoice_id', 'file', 'submission_key']), $request->user()->id, $request->file('file'));

        return $this->ok($payment, 201);
    }

    public function uploadProof(Request $request, string $payment)
    {
        $request->validate(['file' => ['required', 'file', 'max:5120', 'mimes:jpg,jpeg,png,webp,pdf']]);
        if ($this->portal->active()) return $this->ok($this->portal->payment($this->portal->request('POST', 'payments/'.$payment.'/proof', $this->portal->proofData($request->file('file')))['data']));
        $payment = Payment::findOrFail($payment);
        $this->authorizeOwnership($payment);

        $request->validate(['file' => ['required', 'file', 'max:5120', 'mimes:jpg,jpeg,png,webp,pdf']]);

        try {
            $proof = $this->submission->attachProof($payment, $request->file('file'), $request->user()->id);
        } catch (\App\Domain\Payment\Services\PaymentException $e) {
            throw ValidationException::withMessages(['file' => [$e->getMessage()]]);
        }

        return $this->ok($proof, 201);
    }

    public function resubmit(SubmitPaymentRequest $request, string $payment)
    {
        if ($this->portal->active()) {
            $old = $this->portal->request('GET', 'payments/'.$payment)['data'];
            abort_unless($old['status'] === 'REJECTED' && $old['invoice_id'] === $request->input('invoice_id'), 409);
            return $this->store($request);
        }
        $payment = Payment::findOrFail($payment);
        $this->authorizeOwnership($payment);

        $new = $this->submission->resubmit($payment, $request->safe()->except(['invoice_id', 'file', 'submission_key']), $request->user()->id);

        return $this->ok($new, 201);
    }

    public function downloadProof(string $payment, string $proof)
    {
        if ($this->portal->active()) return $this->portal->download($payment, $proof);
        $payment = Payment::findOrFail($payment);
        $proof = PaymentProof::findOrFail($proof);
        $this->authorizeOwnership($payment);
        abort_unless($proof->payment_id === $payment->id, 404);

        return \Illuminate\Support\Facades\Storage::disk($proof->disk)->response($proof->path, $proof->original_filename);
    }

    private function authorizeOwnership(Payment $payment): void
    {
        abort_unless($payment->tenant_id === $this->context->tenantId(), 404);
    }
}
