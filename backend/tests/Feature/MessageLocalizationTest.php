<?php

namespace Tests\Feature;

use App\Domain\Shared\Support\Messages;
use Illuminate\Support\Facades\Lang;
use Tests\TestCase;

/** Messages render per locale from the generated catalog; English stays the fallback and the stored default. */
class MessageLocalizationTest extends TestCase
{
    public function test_text_is_english_unless_a_locale_is_given_and_localized_follows_the_request_locale(): void
    {
        app()->setLocale('id');
        $params = ['code' => '9ZZ9', 'config_code' => '1.1'];

        $this->assertSame("9ZZ9 is not a position of this vehicle's Wheels Configuration (1.1).", Messages::text('validation.tire.codeNotPositionVehicleSWheels', $params), 'Stored text stays English.');
        $this->assertSame('9ZZ9 bukan posisi pada Konfigurasi Roda kendaraan ini (1.1).', Messages::localized('validation.tire.codeNotPositionVehicleSWheels', $params));
        $this->assertSame('9ZZ9 bukan posisi pada Konfigurasi Roda kendaraan ini (1.1).', Messages::text('validation.tire.codeNotPositionVehicleSWheels', $params, 'id'));
    }

    public function test_nested_messages_render_in_the_same_locale(): void
    {
        $label = Messages::make('tire.reasons.damageLabel', ['n' => 1, 'type' => Messages::make('tire.damageTypes.cut'), 'location' => Messages::make('tire.damageLocations.tread')]);
        $en = Messages::render($label);
        $id = Messages::render($label, 'id');

        $this->assertSame('Damage 1 (cut on tread)', $en);
        $this->assertNotSame($en, $id);
        $this->assertStringNotContainsString('cut', $id);
    }

    public function test_a_key_missing_in_a_locale_falls_back_to_english(): void
    {
        $this->assertFalse(Lang::has('catalog.validation.tire.codeNotPositionVehicleSWheels', 'xx', false));
        $this->assertSame("1A is not a position of this vehicle's Wheels Configuration (2).", Messages::text('validation.tire.codeNotPositionVehicleSWheels', ['code' => '1A', 'config_code' => '2'], 'xx'));
    }
}
