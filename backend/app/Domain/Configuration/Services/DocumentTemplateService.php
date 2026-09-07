<?php

namespace App\Domain\Configuration\Services;

use App\Domain\Configuration\Models\ConfigurationSet;
use App\Domain\Configuration\Models\ConfigurationVersion;

/**
 * Section 10: thin wrapper around the generic ConfigurationService for
 * type=TEMPLATE, adding the one template-specific rule — publish is
 * rejected unless the draft's html body passes TemplateValidator for its
 * document type (Section 9's missing/unknown-variable gate).
 */
class DocumentTemplateService
{
    public function __construct(
        private readonly ConfigurationService $configuration,
        private readonly TemplateValidator $validator,
    ) {}

    public function findOrCreateSet(?string $tenantId, string $documentType, string $scopeType, ?string $scopeResourceId, string $name, bool $isSystem = false): ConfigurationSet
    {
        return $this->configuration->findOrCreateSet($tenantId, ConfigurationSet::TYPE_TEMPLATE, $documentType, $scopeType, $scopeResourceId, $name, $isSystem);
    }

    public function createDraft(ConfigurationSet $set, array $payload, ?string $userId, ?string $changeSummary = null): ConfigurationVersion
    {
        return $this->configuration->createDraft($set, $payload, $userId, $changeSummary);
    }

    public function updateDraft(ConfigurationVersion $version, array $payload, ?string $changeSummary = null): ConfigurationVersion
    {
        return $this->configuration->updateDraft($version, $payload, $changeSummary);
    }

    public function publish(ConfigurationVersion $version, string $documentType, ?string $userId): ConfigurationVersion
    {
        return $this->configuration->publish($version, $userId, function (array $payload) use ($documentType) {
            $this->validator->validate($documentType, $payload['html'] ?? '');
        });
    }

    public function archive(ConfigurationVersion $version, ?string $userId): ConfigurationVersion
    {
        return $this->configuration->archive($version, $userId);
    }
}
