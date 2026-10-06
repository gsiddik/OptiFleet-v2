<?php

namespace Tests\Feature;

use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * i18n structural preparation S7: framework validation messages can be served in Indonesian from
 * lang/id, resolved per request (user preferred → tenant default → en). Runtime resolution is off by
 * default, so responses stay English until the rollout. Validation rules themselves never change.
 */
class ValidationLocalizationTest extends TestCase
{
    private const DATASET = __DIR__.'/../../../docs/i18n/12-en-id-translation-dataset-final.csv';

    /** @return array{0: \App\Domain\Identity\Models\Tenant, 1: \App\Models\User, 2: array} */
    private function setUpTenantUser(): array
    {
        $tenant = $this->makeTenant(['code' => 'VLC-'.Str::random(4)]);
        foreach (['VEHICLE', 'MAINTENANCE', 'WORKSHOP', 'WORK_ORDER'] as $module) {
            $this->grantModule($tenant, $module);
        }
        [$user, $token] = $this->makeTenantUser($tenant, ['work_order.view', 'work_order.create']);

        return [$tenant, $user, $this->authHeaders($token)];
    }

    private function requiredMessage(array $headers): string
    {
        $this->app['auth']->forgetGuards();

        return $this->postJson('/api/v1/app/work-orders', [], $headers)->assertStatus(422)->json('errors.vehicle_id.0');
    }

    public function test_responses_stay_english_while_runtime_locale_resolution_is_off(): void
    {
        [$tenant, $user, $headers] = $this->setUpTenantUser();
        $user->forceFill(['preferred_locale' => 'id'])->save();
        $tenant->forceFill(['default_locale' => 'id'])->save();

        $this->assertFalse((bool) config('app.runtime_locale_resolution'));
        $this->assertSame('The vehicle id field is required.', $this->requiredMessage($headers));
    }

    public function test_validation_messages_follow_user_then_tenant_locale_when_enabled(): void
    {
        config(['app.runtime_locale_resolution' => true]);
        [$tenant, $user, $headers] = $this->setUpTenantUser();

        $this->assertSame('The vehicle id field is required.', $this->requiredMessage($headers), 'Nothing set → English.');
        $tenant->forceFill(['default_locale' => 'id'])->save();
        $this->assertSame('Kolom vehicle id wajib diisi.', $this->requiredMessage($headers), 'Tenant default.');
        $user->forceFill(['preferred_locale' => 'en'])->save();
        $this->assertSame('The vehicle id field is required.', $this->requiredMessage($headers), 'User preference wins.');
        $user->forceFill(['preferred_locale' => 'id'])->save();
        $tenant->forceFill(['default_locale' => 'en'])->save();
        $this->assertSame('Kolom vehicle id wajib diisi.', $this->requiredMessage($headers));
    }

    public function test_rules_without_an_indonesian_message_fall_back_to_english(): void
    {
        $this->assertSame('The x field must be accepted.', __('validation.accepted', ['attribute' => 'x'], 'id'));
        $this->assertSame('Kolom x wajib diisi.', __('validation.required', ['attribute' => 'x'], 'id'));
    }

    public function test_indonesian_messages_match_the_dataset_and_keep_every_placeholder(): void
    {
        $dataset = [];
        $handle = fopen(self::DATASET, 'r');
        $header = fgetcsv($handle, escape: '\\');
        while (($row = fgetcsv($handle, escape: '\\')) !== false) {
            $row = array_combine($header, $row);
            $dataset[$row['translation_key']] = $row;
        }
        fclose($handle);

        $en = require base_path('lang/en/validation.php');
        $flatten = function (array $messages, string $prefix = '') use (&$flatten): array {
            $out = [];
            foreach ($messages as $key => $value) {
                if (in_array($key, ['custom', 'attributes'], true) && $prefix === '') {
                    continue;
                }
                is_array($value) ? $out += $flatten($value, $prefix.$key.'.') : $out[$prefix.$key] = $value;
            }

            return $out;
        };
        $enFlat = $flatten($en);
        $idFlat = $flatten(require base_path('lang/id/validation.php'));

        $this->assertNotEmpty($idFlat);
        foreach ($idFlat as $key => $text) {
            $row = $dataset['validation.'.$key] ?? null;
            $this->assertNotNull($row, "validation.{$key} is in the dataset");
            $this->assertSame($row['translated_text_id'], $text, "validation.{$key} matches the dataset");
            $this->assertSame($row['source_text_en'], $enFlat[$key] ?? null, "validation.{$key} English source matches lang/en");
            preg_match_all('/:\w+/', $enFlat[$key], $enParams);
            preg_match_all('/:\w+/', $text, $idParams);
            $this->assertEqualsCanonicalizing(array_unique($enParams[0]), array_unique($idParams[0]), "validation.{$key} keeps its placeholders");
        }
        foreach ($dataset as $key => $row) {
            if (str_contains($row['structural_blocker'], 'FRAMEWORK_VALIDATION_LOCALIZATION')) {
                $this->assertArrayHasKey(substr($key, strlen('validation.')), $idFlat, "{$key} is translated in lang/id");
            }
        }
    }

    public function test_published_english_messages_are_the_framework_defaults(): void
    {
        $this->assertSame(
            require base_path('vendor/laravel/framework/src/Illuminate/Translation/lang/en/validation.php'),
            require base_path('lang/en/validation.php'),
        );
    }
}
