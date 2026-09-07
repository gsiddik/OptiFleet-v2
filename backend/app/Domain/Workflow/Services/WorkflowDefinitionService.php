<?php

namespace App\Domain\Workflow\Services;

use App\Domain\Configuration\Models\ConfigurationSet;
use App\Domain\Configuration\Models\ConfigurationVersion;
use App\Domain\Configuration\Services\ConfigurationService;

/**
 * Section 14/27: thin wrapper around the generic ConfigurationService for
 * type=WORKFLOW (code = resource type, e.g. "maintenance_request"),
 * exactly mirroring DocumentTemplateService — the only workflow-specific
 * rule is that publish is rejected unless the draft passes
 * WorkflowDefinitionValidator.
 */
class WorkflowDefinitionService
{
    public function __construct(
        private readonly ConfigurationService $configuration,
        private readonly WorkflowDefinitionValidator $validator,
    ) {}

    public function findOrCreateSet(?string $tenantId, string $resourceType, string $scopeType, ?string $scopeResourceId, string $name, bool $isSystem = false): ConfigurationSet
    {
        return $this->configuration->findOrCreateSet($tenantId, ConfigurationSet::TYPE_WORKFLOW, $resourceType, $scopeType, $scopeResourceId, $name, $isSystem);
    }

    public function createDraft(ConfigurationSet $set, array $payload, ?string $userId, ?string $changeSummary = null): ConfigurationVersion
    {
        return $this->configuration->createDraft($set, $payload, $userId, $changeSummary);
    }

    public function updateDraft(ConfigurationVersion $version, array $payload, ?string $changeSummary = null): ConfigurationVersion
    {
        return $this->configuration->updateDraft($version, $payload, $changeSummary);
    }

    public function publish(ConfigurationVersion $version, ?string $userId): ConfigurationVersion
    {
        return $this->configuration->publish($version, $userId, fn (array $payload) => $this->validator->validate($payload));
    }

    public function archive(ConfigurationVersion $version, ?string $userId): ConfigurationVersion
    {
        return $this->configuration->archive($version, $userId);
    }
}
