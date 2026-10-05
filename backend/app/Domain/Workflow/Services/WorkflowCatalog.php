<?php

namespace App\Domain\Workflow\Services;

use App\Domain\Configuration\Models\ConfigurationSet;

/**
 * What a workflow for one resource type may contain. A document's statuses are fixed by the
 * domain (its status column only stores these values), and a transition only does something when
 * the module has an action that moves the document into its target status. Both come from the
 * resource's protected platform default workflow (seeded from the modules' own transitions), so a
 * tenant can rearrange, add and remove transitions between real statuses but can never add a
 * status the database cannot store or a transition no action can ever execute.
 *
 * Resource types without a platform default have no catalog (no extra restriction).
 */
class WorkflowCatalog
{
    /** @var array<string, ?array> */
    private array $cache = [];

    /**
     * @return array{statuses: list<array{code: string, display_name: string}>, targets: list<string>, entry_statuses: list<string>}|null
     */
    public function forResource(string $resourceType): ?array
    {
        if (array_key_exists($resourceType, $this->cache)) {
            return $this->cache[$resourceType];
        }
        $set = ConfigurationSet::query()->withoutGlobalScopes()
            ->whereNull('tenant_id')->where('type', ConfigurationSet::TYPE_WORKFLOW)->where('code', $resourceType)
            ->first();
        $payload = $set?->publishedVersion()?->payload;
        if (! $payload) {
            return $this->cache[$resourceType] = null;
        }

        return $this->cache[$resourceType] = [
            'statuses' => array_values(array_map(fn ($s) => [
                'code' => (string) $s['code'],
                'display_name' => (string) ($s['display_name'] ?? $s['code']),
            ], $payload['statuses'] ?? [])),
            'targets' => array_values(array_unique(array_map(fn ($t) => (string) $t['to_status'], $payload['transitions'] ?? []))),
            'entry_statuses' => array_values(array_map(fn ($s) => (string) $s['code'], array_filter($payload['statuses'] ?? [], fn ($s) => ! empty($s['is_start'])))),
        ];
    }
}
