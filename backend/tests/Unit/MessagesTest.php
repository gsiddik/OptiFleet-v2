<?php

namespace Tests\Unit;

use App\Domain\Shared\Support\Messages;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * i18n structural preparation (whole-sentence templates): every backend message is one keyed
 * sentence that matches the EN-ID dataset, and rendering reproduces the previous English text.
 */
class MessagesTest extends TestCase
{
    /** @return array<string, array<string, string>> dataset rows keyed by translation_key */
    private function dataset(): array
    {
        $handle = fopen(dirname(__DIR__, 3).'/docs/i18n/12-en-id-translation-dataset-final.csv', 'r');
        $header = fgetcsv($handle, escape: '');
        $rows = [];
        while (($row = fgetcsv($handle, escape: '')) !== false) {
            $assoc = array_combine($header, array_pad($row, count($header), ''));
            $rows[$assoc['translation_key']] = $assoc;
        }
        fclose($handle);

        return $rows;
    }

    /** @return list<string> */
    private function placeholders(string $text): array
    {
        preg_match_all('/\{\{(\w+)\}\}/', $text, $m);
        $names = $m[1];
        sort($names);

        return $names;
    }

    public function test_every_message_matches_the_dataset_and_has_an_indonesian_translation(): void
    {
        $dataset = $this->dataset();
        $problems = [];
        foreach (Messages::EN as $key => $template) {
            $row = $dataset[$key] ?? null;
            if ($row === null) {
                $problems[] = "{$key}: missing from the dataset";

                continue;
            }
            if ($row['source_text_en'] !== $template) {
                $problems[] = "{$key}: English differs from the dataset";
            }
            if ($row['translated_text_id'] === '' || $this->placeholders($row['translated_text_id']) !== $this->placeholders($template)) {
                $problems[] = "{$key}: Indonesian missing or with different parameters";
            }
        }
        $this->assertSame([], $problems);
        $this->assertGreaterThan(60, count(Messages::EN));
    }

    public function test_rendering_reproduces_the_previous_english_text(): void
    {
        $this->assertSame(
            'Cannot enable module TIRE: missing required module(s): FLEET, INVENTORY',
            Messages::text('errors.entitlement.cannotEnableModuleWithList', ['moduleCode' => 'TIRE', 'modules' => 'FLEET, INVENTORY'])
        );
        $this->assertSame('How to import New Stock tires — Tyre X (TX-1)', Messages::text('documents.tireImport.howToTitleWithCode', ['productName' => 'Tyre X', 'productCode' => 'TX-1']));
        $this->assertSame('How to import New Stock tires — Tyre X', Messages::text('documents.tireImport.howToTitle', ['productName' => 'Tyre X']));
        $this->assertSame('Front Axle 1 Left Wheel 2', Messages::text('tire.wheelConfiguration.positionLabel', ['axleGroup' => Messages::text('common.fields.front'), 'axle' => 1, 'side' => 'Left', 'wheel' => 2]));
        $this->assertSame('Bead damage: bead wire damaged.', Messages::text('tire.reasons.beadDamageDetail', ['condition' => Messages::text('tire.conditions.beadWireDamaged')]));
        $this->assertSame('Only a requested part request can be cancelled.', Messages::text('errors.workOrder.partRequestOnlyRequestedCancel'));
        $this->assertSame('You cannot remove "role.manage" from a role that is your only source of it — you would lose access to role management.', Messages::text('validation.accessControl.cannotRemoveManagePermission', ['permission' => 'role.manage']));
    }

    public function test_a_missing_parameter_stays_visible_and_an_unknown_key_fails_loudly(): void
    {
        $this->assertSame('Vehicle transfer {{transferId}}', Messages::text('vehicle.notes.vehicleTransfer'));
        $this->expectException(InvalidArgumentException::class);
        Messages::text('no.such.key');
    }
}
