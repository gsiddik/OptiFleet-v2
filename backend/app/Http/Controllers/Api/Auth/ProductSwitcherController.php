<?php

namespace App\Http\Controllers\Api\Auth;

use App\Domain\Governance\Exceptions\ProductNotAvailable;
use App\Domain\Governance\Exceptions\ProductSessionEnded;
use App\Domain\Governance\ProductSwitcher;
use App\Domain\Shared\Support\Messages;
use App\Http\Controllers\Controller;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The signed-in person's own product switcher (audit A29/R06). Own-account endpoints: they act for the session, need no
 * permission, and only an OptiNexus sign-in (token ability `sso`) has anything to switch to. See ProductSwitcher.
 */
class ProductSwitcherController extends Controller
{
    public function __construct(
        private readonly ProductSwitcher $switcher,
        private readonly TenantContext $context,
    ) {}

    /** The products this person may open from here, as OptiNexus answers now (cached at most 300 s). */
    public function index(Request $request): JsonResponse
    {
        try {
            return $this->ok($this->switcher->list($request->user(), $this->context->tenantId(), $this->viaSso($request)))
                ->header('Cache-Control', 'no-store');
        } catch (ProductSessionEnded $e) {
            return $this->sessionEnded($e);
        }
    }

    /** Where to send the browser for one product. The answer of OptiNexus is asked again first; a code it does not offer is refused. */
    public function launch(Request $request): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9._-]+$/']]);

        try {
            return $this->ok($this->switcher->launch($request->user(), $this->context->tenantId(), $this->viaSso($request), $data['code']))
                ->header('Cache-Control', 'no-store');
        } catch (ProductSessionEnded $e) {
            return $this->sessionEnded($e);
        } catch (ProductNotAvailable) {
            return response()->json(['message' => Messages::localized('errors.http.productNotAvailable'), 'code' => ProductNotAvailable::CODE], 403);
        }
    }

    private function viaSso(Request $request): bool
    {
        return (bool) $request->user()?->currentAccessToken()?->can('sso');
    }

    private function sessionEnded(ProductSessionEnded $e): JsonResponse
    {
        return response()->json([
            'message' => Messages::localized('errors.http.accessWithdrawnCentrally'),
            'code' => 'SESSION_ENDED_CENTRALLY',
            'reason' => $e->reason,
        ], 401);
    }
}
