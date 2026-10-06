<?php

namespace App\Domain\Workflow\Support;

use App\Domain\DocumentGeneration\Support\DocumentLocale;
use App\Domain\Workflow\Services\WorkflowValidationException;

/**
 * i18n structural preparation (S8): a tenant's workflow action labels are its own configuration, so their
 * other-language wording lives in the same versioned workflow payload, never in a translation table:
 *
 *   payload.locales.<locale>.action_labels.<action_code> = "Setujui"
 *
 * A label without an entry for the locale falls back to the transition's own `action_label`, then to the
 * action code. Publishing a version validates the entries like the rest of the definition.
 */
final class WorkflowLabels
{
    public static function actionLabel(array $payload, array $transition, string $locale = 'en'): string
    {
        $code = (string) ($transition['action_code'] ?? '');

        return (string) ($payload['locales'][$locale]['action_labels'][$code] ?? $transition['action_label'] ?? $code);
    }

    /** @throws WorkflowValidationException */
    public static function validateLocales(array $payload): void
    {
        $actionCodes = array_column($payload['transitions'] ?? [], 'action_code');
        foreach ($payload['locales'] ?? [] as $locale => $labels) {
            if (! DocumentLocale::isSupported((string) $locale)) {
                throw new WorkflowValidationException("Unsupported workflow label locale '{$locale}'.");
            }
            foreach ($labels['action_labels'] ?? [] as $code => $label) {
                if (! in_array($code, $actionCodes, true)) {
                    throw new WorkflowValidationException("Localized label for unknown action '{$code}'.");
                }
                if (! is_string($label) || trim($label) === '') {
                    throw new WorkflowValidationException("Localized label for action '{$code}' must be text.");
                }
            }
        }
    }
}
