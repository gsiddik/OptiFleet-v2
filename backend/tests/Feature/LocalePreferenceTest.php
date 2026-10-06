<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * i18n: a user's preferred language (users.preferred_locale) and a tenant's default language
 * (tenants.default_locale). Locale values are codes (en / id), never display names.
 */
class LocalePreferenceTest extends TestCase
{
    public function test_a_user_sets_clears_and_keeps_a_preferred_locale_across_logins(): void
    {
        $tenant = $this->makeTenant(['code' => 'LOC-'.Str::random(4)]);
        [$user, $token] = $this->makeTenantUser($tenant);
        $headers = $this->authHeaders($token) + ['X-Tenant-ID' => $tenant->id];

        $me = $this->getJson('/api/v1/auth/me', $headers)->assertOk();
        $this->assertSame([null, 'en'], [$me->json('data.preferred_locale'), $me->json('data.tenant_default_locale')], 'New tenants default to English.');

        $this->patchJson('/api/v1/auth/me/preferences', ['preferred_locale' => 'id'], $headers)->assertOk()->assertJsonPath('data.preferred_locale', 'id');
        $this->assertSame('id', $user->fresh()->preferred_locale);

        // Persisted: a new login returns the preference.
        $this->app['auth']->forgetGuards();
        $login = $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password'])->assertOk();
        $this->assertSame('id', $login->json('data.user.preferred_locale'));

        $this->patchJson('/api/v1/auth/me/preferences', ['preferred_locale' => 'Bahasa Indonesia'], $headers)->assertStatus(422)->assertJsonValidationErrors('preferred_locale');
        $this->patchJson('/api/v1/auth/me/preferences', ['preferred_locale' => null], $headers)->assertOk()->assertJsonPath('data.preferred_locale', null);
        $this->assertNull($user->fresh()->preferred_locale);
    }

    public function test_a_platform_user_can_set_a_preferred_locale(): void
    {
        [$user, $token] = $this->makePlatformUser();
        $this->patchJson('/api/v1/auth/me/preferences', ['preferred_locale' => 'id'], $this->authHeaders($token))->assertOk()
            ->assertJsonPath('data.preferred_locale', 'id')->assertJsonPath('data.tenant_default_locale', null);
    }

    public function test_the_tenant_default_locale_is_set_through_the_company_profile_with_permission(): void
    {
        $tenant = $this->makeTenant(['code' => 'LOC-'.Str::random(4)]);
        [, $token] = $this->makeTenantUser($tenant, ['company.view', 'company.update']);
        $headers = $this->authHeaders($token) + ['X-Tenant-ID' => $tenant->id];

        $this->putJson('/api/v1/app/account/company', ['workshop_working_days' => 6, 'default_locale' => 'id'], $headers)->assertOk()->assertJsonPath('data.default_locale', 'id');
        $this->assertSame('id', $tenant->fresh()->default_locale);
        $this->putJson('/api/v1/app/account/company', ['workshop_working_days' => 6, 'default_locale' => 'English'], $headers)->assertStatus(422);

        [, $readOnly] = $this->makeTenantUser($tenant, ['company.view']);
        $this->app['auth']->forgetGuards();
        $this->putJson('/api/v1/app/account/company', ['workshop_working_days' => 6, 'default_locale' => 'en'], $this->authHeaders($readOnly) + ['X-Tenant-ID' => $tenant->id])->assertForbidden();
    }

    public function test_a_platform_admin_sets_a_tenant_default_locale(): void
    {
        $tenant = $this->makeTenant(['code' => 'LOC-'.Str::random(4)]);
        [, $token] = $this->makePlatformUser(['tenant.update']);
        $this->putJson("/api/v1/platform/tenants/{$tenant->id}", ['default_locale' => 'id'], $this->authHeaders($token))->assertOk();
        $this->assertSame('id', $tenant->fresh()->default_locale);
    }

    public function test_the_backfill_defaults_existing_tenants_to_english_and_keeps_a_chosen_locale(): void
    {
        $unset = $this->makeTenant(['code' => 'LOC-'.Str::random(4)]);
        $chosen = $this->makeTenant(['code' => 'LOC-'.Str::random(4)]);
        DB::table('tenants')->where('id', $unset->id)->update(['default_locale' => null]);
        DB::table('tenants')->where('id', $chosen->id)->update(['default_locale' => 'id']);

        $migration = require database_path('migrations/2026_10_14_000001_default_tenant_locale_to_english.php');
        $migration->up();
        $migration->up();

        $this->assertSame('en', $unset->fresh()->default_locale);
        $this->assertSame('id', $chosen->fresh()->default_locale);
    }

    public function test_sign_in_and_access_errors_follow_the_request_language_with_unchanged_status(): void
    {
        $tenant = $this->makeTenant(['code' => 'LOC-'.Str::random(4)]);
        [$user, $token] = $this->makeTenantUser($tenant);

        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'wrong'], ['Accept-Language' => 'id'])
            ->assertStatus(422)->assertJsonPath('errors.email.0', 'Kredensial yang diberikan salah.');
        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'wrong'])
            ->assertStatus(422)->assertJsonPath('errors.email.0', 'The provided credentials are incorrect.');

        $user->forceFill(['status' => 'inactive'])->save();
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/auth/me', $this->authHeaders($token) + ['Accept-Language' => 'id'])->assertStatus(403)
            ->assertJsonPath('message', 'Akun pengguna ini tidak aktif.');
    }
}
