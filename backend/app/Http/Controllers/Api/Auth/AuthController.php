<?php

namespace App\Http\Controllers\Api\Auth;

use App\Domain\DocumentGeneration\Support\DocumentLocale;
use App\Domain\Identity\Models\TenantUser;
use App\Domain\Shared\Support\Messages;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\SwitchTenantRequest;
use App\Http\Support\CurrentUserPresenter;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function __construct(
        private readonly CurrentUserPresenter $presenter,
        private readonly TenantContext $context,
    ) {}

    public function login(LoginRequest $request)
    {
        $user = User::query()->where('email', $request->string('email'))->first();

        if (! $user || ! Hash::check($request->string('password'), $user->password)) {
            throw ValidationException::withMessages([
                'email' => [Messages::localized('validation.auth.providedCredentialsIncorrect')],
            ]);
        }

        if (! $user->isActive()) {
            throw ValidationException::withMessages([
                'email' => [Messages::localized('validation.auth.accountBeenDeactivated')],
            ]);
        }

        if ($user->isPlatformUser()) {
            $token = $user->createToken('platform-session', ['platform'])->plainTextToken;
        } else {
            $membership = TenantUser::query()
                ->where('user_id', $user->id)
                ->where('status', 'active')
                ->whereHas('tenant', fn ($q) => $q->where('status', 'ACTIVE'))
                ->first();

            if (! $membership) {
                throw ValidationException::withMessages([
                    'email' => [Messages::localized('validation.auth.noActiveTenantMembershipFoundAccount')],
                ]);
            }

            $token = $user->createToken('tenant-session', ['tenant:'.$membership->tenant_id])->plainTextToken;
            $this->context->setTenantId($membership->tenant_id);
        }

        $user->forceFill(['last_login_at' => now()])->save();
        $this->context->setUser($user);

        return $this->ok([
            'token' => $token,
            'user' => $this->presenter->present($user),
        ]);
    }

    public function logout()
    {
        $this->context->user()?->currentAccessToken()?->delete();

        return $this->message('Logged out.');
    }

    public function me()
    {
        return $this->ok($this->presenter->present($this->context->user()));
    }

    /**
     * The signed-in user's own preferences. `preferred_locale`: en | id | null (null = follow the tenant
     * default, then the browser, then English). Any authenticated user may change their own.
     */
    public function updatePreferences(Request $request)
    {
        $validated = $request->validate([
            'preferred_locale' => ['present', 'nullable', Rule::in(DocumentLocale::SUPPORTED)],
        ]);
        $user = $this->context->user();
        $user->forceFill(['preferred_locale' => $validated['preferred_locale']])->save();

        return $this->ok($this->presenter->present($user->fresh()));
    }

    public function switchTenant(SwitchTenantRequest $request)
    {
        $user = $this->context->user();
        $tenantId = $request->string('tenant_id');

        $membership = TenantUser::query()
            ->where('user_id', $user->id)
            ->where('tenant_id', $tenantId)
            ->where('status', 'active')
            ->whereHas('tenant', fn ($q) => $q->where('status', 'ACTIVE'))
            ->first();

        if (! $membership) {
            throw ValidationException::withMessages([
                'tenant_id' => [Messages::localized('validation.auth.youDoNotActiveMembershipTenant')],
            ]);
        }

        $token = $user->createToken('tenant-session', ['tenant:'.$tenantId])->plainTextToken;

        return $this->ok(['token' => $token]);
    }
}
