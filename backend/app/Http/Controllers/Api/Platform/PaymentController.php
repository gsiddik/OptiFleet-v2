<?php

namespace App\Http\Controllers\Api\Platform;

use App\Domain\Payment\Models\Payment;
use App\Domain\Payment\Models\PaymentProof;
use App\Domain\Payment\Services\PaymentVerificationService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Platform\RejectPaymentRequest;
use App\Http\Requests\Platform\VerifyPaymentRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class PaymentController extends Controller
{
    public function __construct(private readonly PaymentVerificationService $verification) {}

    public function index(Request $request)
    {
        $query = Payment::query()->with(['tenant', 'invoice']);

        if ($status = $request->string('status')->value()) {
            $query->where('status', $status);
        }

        return $this->paginated($query->latest('created_at')->paginate($request->integer('per_page', 15)));
    }

    public function show(Payment $payment)
    {
        return $this->ok($payment->load(['tenant', 'invoice', 'proofs', 'submitter', 'verifier']));
    }

    public function verify(VerifyPaymentRequest $request, Payment $payment)
    {
        $updated = $this->verification->verify($payment, $request->user()->id, $request->input('note'));

        return $this->ok($updated->load('invoice'));
    }

    public function reject(RejectPaymentRequest $request, Payment $payment)
    {
        $updated = $this->verification->reject($payment, $request->user()->id, $request->input('note'));

        return $this->ok($updated);
    }

    public function downloadProof(Payment $payment, PaymentProof $proof)
    {
        abort_unless($proof->payment_id === $payment->id, 404);

        return Storage::disk($proof->disk)->response($proof->path, $proof->original_filename);
    }
}
