<?php

namespace Tests\Feature;

use App\Domain\AccessControl\Models\Role;
use App\Domain\Shared\Support\ResponseMessageLocalizer;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * i18n: English domain messages are rendered in the request locale at the response boundary. Same status,
 * same codes, same fields — only the language of `message` / `errors` follows the locale.
 */
class ResponseMessageLocalizationTest extends TestCase
{
    public function test_a_domain_error_follows_the_request_locale(): void
    {
        [, $token] = $this->makePlatformUser(['role.update', 'role.view']);
        $role = Role::query()->create(['tenant_id' => null, 'name' => 'Seeded Ops', 'scope' => 'platform', 'is_system' => true]);
        $url = "/api/v1/platform/roles/{$role->id}";
        $english = 'The platform superadmin role always holds every platform permission and cannot be edited.';

        $en = $this->putJson($url, ['name' => 'Renamed'], $this->authHeaders($token))->assertStatus(422);
        $this->assertSame([$english, [$english]], [$en->json('message'), $en->json('errors.role')], 'No language sent: English, unchanged.');

        $id = $this->putJson($url, ['name' => 'Renamed'], $this->authHeaders($token) + ['Accept-Language' => 'id'])->assertStatus(422);
        $indonesian = __('catalog.validation.accessControl.platformSuperadminRoleAlwaysHoldsEvery', [], 'id');
        $this->assertNotSame($english, $indonesian);
        $this->assertSame([$indonesian, [$indonesian]], [$id->json('message'), $id->json('errors.role')]);
        $this->assertSame(array_keys($en->json()), array_keys($id->json()), 'Same response shape.');
        $this->assertSame('Seeded Ops', $role->fresh()->name);
    }

    public function test_a_permission_error_keeps_its_value_inside_the_translated_sentence(): void
    {
        $tenant = $this->makeTenant(['code' => 'RML-'.Str::random(4)]);
        [$user, $token] = $this->makeTenantUser($tenant, ['company.view']);
        $headers = $this->authHeaders($token) + ['X-Tenant-ID' => $tenant->id];
        $url = '/api/v1/app/account/company';

        $this->putJson($url, ['workshop_working_days' => 6], $headers)->assertForbidden()
            ->assertJsonPath('message', 'Missing required permission: company.update');

        $user->forceFill(['preferred_locale' => 'id'])->save();
        $this->app['auth']->forgetGuards();
        $this->putJson($url, ['workshop_working_days' => 6], $headers)->assertForbidden()
            ->assertJsonPath('message', 'Izin yang diperlukan tidak dimiliki: company.update');
    }

    public function test_unknown_tenant_text_and_already_localized_text_stay_unchanged(): void
    {
        $this->app->setLocale('id');
        $this->assertSame('Ban depan bocor di Tol Cipularang', ResponseMessageLocalizer::localize('Ban depan bocor di Tol Cipularang', 'id'));
        $this->assertSame('Some text no dataset row has.', ResponseMessageLocalizer::localize('Some text no dataset row has.', 'id'));
        $this->assertSame('The name of a system role cannot be changed.', ResponseMessageLocalizer::localize('The name of a system role cannot be changed.', 'en'));
        $payload = ResponseMessageLocalizer::localizePayload([
            'message' => 'The name of a system role cannot be changed.',
            'errors' => ['name' => ['The name of a system role cannot be changed.', 'Nama wajib diisi.']],
            'codes' => ['name' => ['ROLE_NAME_LOCKED']],
        ], 'id');
        $this->assertSame(['Nama peran sistem tidak dapat diubah.', 'Nama wajib diisi.'], $payload['errors']['name']);
        $this->assertSame(['name' => ['ROLE_NAME_LOCKED']], $payload['codes']);
        $this->assertSame(
            'Peran superadmin platform selalu memiliki semua izin platform dan tidak dapat diubah. (dan 2 kesalahan lainnya)',
            ResponseMessageLocalizer::localize('The platform superadmin role always holds every platform permission and cannot be edited. (and 2 more errors)', 'id'),
        );
    }
}
