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
    ) {}

    public function index(Request $request)
    {
        $query = Payment::query()->where('tenant_id', $this->context->tenantId())->with(['proofs', 'invoice']);

        if ($status = $request->string('status')->value()) {
            $query->where('status', $status);
        }

        return $this->paginated($query->latest('created_at')->paginate($request->integer('per_page', 15)));
    }

    public function show(Payment $payment)
    {
        $this->authorizeOwnership($payment);

        return $this->ok($payment->load(['proofs', 'invoice']));
    }

    public function store(SubmitPaymentRequest $request)
    {
        $invoice = Invoice::query()->where('tenant_id', $this->context->tenantId())->findOrFail($request->input('invoice_id'));

        $payment = $this->submission->submit($invoice, $request->safe()->except('invoice_id'), $request->user()->id);

        return $this->ok($payment, 201);
    }

    public function uploadProof(Request $request, Payment $payment)
    {
        $this->authorizeOwnership($payment);

        $request->validate(['file' => ['required', 'file', 'max:5120', 'mimes:jpg,jpeg,png,webp,pdf']]);

        try {
            $proof = $this->submission->attachProof($payment, $request->file('file'), $request->user()->id);
        } catch (\App\Domain\Payment\Services\PaymentException $e) {
            throw ValidationException::withMessages(['file' => [$e->getMessage()]]);
        }

        return $this->ok($proof, 201);
    }

    public function resubmit(SubmitPaymentRequest $request, Payment $payment)
    {
        $this->authorizeOwnership($payment);

        $new = $this->submission->resubmit($payment, $request->safe()->except('invoice_id'), $request->user()->id);

        return $this->ok($new, 201);
    }

    public function downloadProof(Payment $payment, PaymentProof $proof)
    {
        $this->authorizeOwnership($payment);
        abort_unless($proof->payment_id === $payment->id, 404);

        return \Illuminate\Support\Facades\Storage::disk($proof->disk)->response($proof->path, $proof->original_filename);
    }

    private function authorizeOwnership(Payment $payment): void
    {
        abort_unless($payment->tenant_id === $this->context->tenantId(), 404);
    }
}
