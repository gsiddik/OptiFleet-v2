<?php

namespace App\Domain\Notification\Services;

use App\Domain\DocumentGeneration\Support\DocumentLocale;
use App\Domain\Identity\Models\Tenant;
use App\Models\User;

/**
 * The language a notification is rendered in for one recipient (i18n rollout): the recipient user's
 * preferred locale → the tenant's default locale → en. A recipient addressed only by email (e.g. a vendor
 * contact) has no preference and gets the tenant default. Recipients come from RecipientResolver, which
 * already confines them to the tenant.
 */
class RecipientLocaleResolver
{
    /** @var array<string, ?string> */
    private array $tenantDefaults = [];

    /** @param array{user_id?: string, email?: string} $target */
    public function resolve(array $target, string $tenantId): string
    {
        $preferred = ! empty($target['user_id'])
            ? User::query()->whereKey($target['user_id'])->value('preferred_locale')
            : null;

        return DocumentLocale::resolve(null, $preferred, $this->tenantDefault($tenantId));
    }

    private function tenantDefault(string $tenantId): ?string
    {
        if (! array_key_exists($tenantId, $this->tenantDefaults)) {
            $this->tenantDefaults[$tenantId] = Tenant::query()->whereKey($tenantId)->value('default_locale');
        }

        return $this->tenantDefaults[$tenantId];
    }
}
