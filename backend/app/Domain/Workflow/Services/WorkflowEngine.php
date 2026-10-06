<?php

namespace App\Domain\Workflow\Services;

use App\Domain\AccessControl\Services\PermissionService;
use App\Domain\Configuration\Models\ConfigurationSet;
use App\Domain\Configuration\Models\ConfigurationVersion;
use App\Domain\Configuration\Services\EffectiveConfigurationResolver;
use App\Domain\Workflow\Support\WorkflowLabels;
use App\Models\User;

/**
 * Section 14/26: resolves the effective PUBLISHED workflow (via the same
 * Workshop -> Branch -> Tenant -> Platform resolver numbering/templates
 * use) and answers "what can this actor do from this status right now" —
 * filtering by required_permission and condition_set. Never mutates
 * anything; callers (Batch E's per-resource services) are responsible for
 * actually writing the resulting status.
 */
class WorkflowEngine
{
    public function __construct(
        private readonly EffectiveConfigurationResolver $resolver,
        private readonly PermissionService $permissions,
        private readonly ConditionEvaluator $conditions,
    ) {}

    public function resolveEffective(string $resourceType, string $tenantId, ?string $branchId = null, ?string $workshopId = null): ?ConfigurationVersion
    {
        return $this->resolver->resolve(ConfigurationSet::TYPE_WORKFLOW, $resourceType, $tenantId, $branchId, $workshopId);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function availableTransitions(ConfigurationVersion $version, string $fromStatus, User $actor, ?string $tenantId, array $context): array
    {
        $available = [];
        foreach ($version->payload['transitions'] ?? [] as $t) {
            if (($t['from_status'] ?? null) !== $fromStatus) {
                continue;
            }
            if (! empty($t['required_permission']) && ! $this->permissions->userHasPermission($actor, $t['required_permission'], $tenantId)) {
                continue;
            }
            if (! $this->conditions->evaluate($t['condition_set'] ?? null, $context)) {
                continue;
            }
            $available[] = $t;
        }

        return $available;
    }

    /**
     * Section 25: the version a resource pinned at creation always wins —
     * an in-flight resource is validated against exactly that version
     * forever, never whatever is currently published. Only a legacy
     * resource with no pinned version (created before this migration)
     * falls back to resolving the tenant's current effective workflow.
     */
    public function resolvePinnedOrEffective(?string $pinnedVersionId, string $resourceType, string $tenantId, ?string $branchId = null, ?string $workshopId = null): ?ConfigurationVersion
    {
        if ($pinnedVersionId) {
            return ConfigurationVersion::query()->find($pinnedVersionId);
        }

        return $this->resolveEffective($resourceType, $tenantId, $branchId, $workshopId);
    }

    public function isTransitionAllowedForVersion(?ConfigurationVersion $version, string $fromStatus, string $toStatus): bool
    {
        if (! $version) {
            return false;
        }

        foreach ($version->payload['transitions'] ?? [] as $t) {
            if (($t['from_status'] ?? null) === $fromStatus && ($t['to_status'] ?? null) === $toStatus) {
                return true;
            }
        }

        return false;
    }

    public function findTransition(ConfigurationVersion $version, string $fromStatus, string $actionCode): ?array
    {
        foreach ($version->payload['transitions'] ?? [] as $t) {
            if (($t['from_status'] ?? null) === $fromStatus && ($t['action_code'] ?? null) === $actionCode) {
                return $t;
            }
        }

        return null;
    }

    /**
     * Section 26: pure, non-mutating preview — the transitions a sample
     * actor could attempt from a sample status, whether each would require
     * approval (and by what rule), and which automated actions are
     * declared. Reads only; writes nothing.
     */
    public function simulate(ConfigurationVersion $version, string $fromStatus, User $actor, ?string $tenantId, array $context): array
    {
        return array_map(fn ($t) => [
            'action_code' => $t['action_code'],
            'action_label' => WorkflowLabels::actionLabel($version->payload ?? [], $t, app()->getLocale()),
            'to_status' => $t['to_status'],
            'requires_approval' => ! empty($t['approval_rule']),
            'approval_rule' => $t['approval_rule'] ?? null,
            'automated_actions' => $t['automated_actions'] ?? [],
        ], $this->availableTransitions($version, $fromStatus, $actor, $tenantId, $context));
    }
}
