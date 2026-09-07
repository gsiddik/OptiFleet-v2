<?php

namespace App\Domain\Configuration\Services;

use App\Domain\Configuration\Models\ConfigurationSet;
use App\Domain\Configuration\Models\ConfigurationVersion;

/**
 * Section 13: resolves the effective PUBLISHED template (Workshop -> Branch
 * -> Tenant default -> Platform fallback, via the shared
 * EffectiveConfigurationResolver) and renders it against a caller-supplied
 * context array, returning exactly which template version was used so the
 * issuing document can preserve that reference forever.
 */
class DocumentTemplateRenderService
{
    public function __construct(
        private readonly EffectiveConfigurationResolver $resolver,
        private readonly TemplateRenderer $renderer,
        private readonly TemplateVariableRegistry $registry,
    ) {}

    public function resolveEffective(string $documentType, string $tenantId, ?string $branchId = null, ?string $workshopId = null, ?string $warehouseId = null): ?ConfigurationVersion
    {
        return $this->resolver->resolve(ConfigurationSet::TYPE_TEMPLATE, $documentType, $tenantId, $branchId, $workshopId, $warehouseId);
    }

    /**
     * @return array{html:string, template_version_id:string, template_version_number:int}
     */
    public function render(string $documentType, array $context, string $tenantId, ?string $branchId = null, ?string $workshopId = null, ?string $warehouseId = null): array
    {
        $version = $this->resolveEffective($documentType, $tenantId, $branchId, $workshopId, $warehouseId);
        if (! $version) {
            throw new TemplateValidationException("No published document template found for type {$documentType}.");
        }

        $context['template_version'] = $version->version_number;
        $context['generated_at'] = now()->toDateTimeString();

        return [
            'html' => $this->renderer->render($version->payload['html'] ?? '', $context),
            'template_version_id' => $version->id,
            'template_version_number' => $version->version_number,
        ];
    }

    /**
     * Section 11: pure, non-mutating preview of a (possibly unpublished)
     * template body against sample or caller-supplied data — never touches
     * the effective-configuration resolver or any live document.
     */
    public function preview(string $documentType, string $html, ?array $sampleContext = null): string
    {
        return $this->renderer->render($html, $sampleContext ?? $this->registry->sampleContext($documentType));
    }
}
