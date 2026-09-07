<?php

namespace App\Http\Controllers\Api\Auth;

use App\Domain\Identity\Models\TenantUser;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\SwitchTenantRequest;
use App\Http\Support\CurrentUserPresenter;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Support\Facades\Hash;
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
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        if (! $user->isActive()) {
            throw ValidationException::withMessages([
                'email' => ['This account has been deactivated.'],
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
                    'email' => ['No active tenant membership found for this account.'],
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
                'tenant_id' => ['You do not have an active membership for this tenant.'],
            ]);
        }

        $token = $user->createToken('tenant-session', ['tenant:'.$tenantId])->plainTextToken;

        return $this->ok(['token' => $token]);
    }
}
