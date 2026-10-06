<?php

namespace App\Domain\DocumentGeneration\Services;

use App\Domain\Configuration\Models\ConfigurationVersion;
use App\Domain\Configuration\Services\DocumentPdfService;
use App\Domain\Configuration\Services\DocumentTemplateRenderService;
use App\Domain\Configuration\Services\TemplateRenderer;
use App\Domain\Configuration\Services\TemplateValidationException;
use App\Domain\DocumentGeneration\Models\DocumentGeneration;
use App\Domain\DocumentGeneration\Support\DocumentLocale;
use App\Domain\DocumentGeneration\Support\DocumentSource;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Shared\Support\DisplayFormat;
use App\Domain\Shared\Support\StatusLabels;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * PRINT_LOCALE_SNAPSHOT. Every printed document is rendered from a DocumentGeneration that pins its
 * locale and its published template version:
 *
 * - Reprint: renders an existing generation with its stored locale and template version, whatever the
 *   user's or tenant's language is now and whatever the template has become since.
 * - Generate New Version: always adds a new generation (current effective template, chosen locale);
 *   history is never overwritten.
 *
 * Only the rendering inputs are pinned. Formatting (dates, numbers) follows the generation's locale at
 * render time; nothing localized is written to business tables.
 */
class DocumentGenerationService
{
    public function __construct(
        private readonly DocumentTemplateRenderService $templates,
        private readonly TemplateRenderer $renderer,
        private readonly DocumentPdfService $pdf,
    ) {}

    /** @return Collection<int, DocumentGeneration> newest first */
    public function history(DocumentSource $source): Collection
    {
        return $this->query($source)->orderByDesc('generated_at')->orderByDesc('created_at')->get();
    }

    /**
     * The generation a plain print shows: the requested one, else the latest, else the first
     * generation of this document — created once even under concurrent first prints.
     */
    public function forPrint(DocumentSource $source, ?string $generationId, ?string $explicitLocale, ?User $user): DocumentGeneration
    {
        if ($generationId !== null) {
            return $this->query($source)->whereKey($generationId)->firstOrFail();
        }

        return DB::transaction(function () use ($source, $explicitLocale, $user) {
            DB::select('select pg_advisory_xact_lock(hashtext(?))', [$this->lockKey($source)]);

            return $this->latest($source) ?? $this->create($source, $explicitLocale, $user);
        });
    }

    /** Generate New Version: a new generation from the current effective template; never touches history. */
    public function generate(DocumentSource $source, ?string $explicitLocale, ?User $user): DocumentGeneration
    {
        return DB::transaction(function () use ($source, $explicitLocale, $user) {
            DB::select('select pg_advisory_xact_lock(hashtext(?))', [$this->lockKey($source)]);

            return $this->create($source, $explicitLocale, $user);
        });
    }

    public function resolveLocale(string $tenantId, ?string $explicitLocale, ?User $user): string
    {
        $tenantDefault = Tenant::query()->whereKey($tenantId)->value('default_locale');

        return DocumentLocale::resolve($explicitLocale, $user?->preferred_locale, $tenantDefault);
    }

    /** The HTML of a generation: its pinned template version, rendered in its locale. */
    public function renderHtml(DocumentSource $source, DocumentGeneration $generation): string
    {
        $context = self::withStatusLabels(($source->context)($generation->locale), $generation->locale);
        $context['template_version'] = $generation->template_version ?? 'default';
        $context['generated_at'] = DisplayFormat::dateTime($generation->generated_at, $generation->locale);

        return $this->renderer->render($this->templateHtml($source, $generation), $context);
    }

    /**
     * Beside every `status` code of the context, its display label in the document's language
     * (`status_label`). The code itself is unchanged, so templates printing {{….status}} keep working.
     */
    public static function withStatusLabels(array $context, string $locale): array
    {
        foreach ($context as $key => $value) {
            if (is_array($value)) {
                $context[$key] = self::withStatusLabels($value, $locale);
            } elseif ($key === 'status' && is_string($value) && ! array_key_exists('status_label', $context)) {
                $context['status_label'] = StatusLabels::localized($value, $locale, 'document');
            }
        }

        return $context;
    }

    public function pdfResponse(DocumentSource $source, DocumentGeneration $generation): Response
    {
        return response($this->pdf->fromHtml($this->renderHtml($source, $generation)), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$source->filename.'"',
            'X-Document-Generation-Id' => $generation->id,
            'X-Document-Locale' => $generation->locale,
            'X-Document-Template-Version' => (string) ($generation->template_version ?? 'default'),
        ]);
    }

    private function create(DocumentSource $source, ?string $explicitLocale, ?User $user): DocumentGeneration
    {
        $version = $this->templates->resolveEffective($source->documentType, $source->tenantId, $source->branchId, $source->workshopId, $source->warehouseId);
        if (! $version && $source->fallbackHtml === null) {
            throw new TemplateValidationException("No published document template found for type {$source->documentType}.");
        }

        return DocumentGeneration::query()->create([
            'tenant_id' => $source->tenantId,
            'document_type' => $source->documentType,
            'source_entity_type' => $source->sourceEntityType,
            'source_entity_id' => $source->sourceEntityId,
            'recipient_partner_id' => $source->recipientPartnerId,
            'locale' => $this->resolveLocale($source->tenantId, $explicitLocale, $user),
            'template_id' => $version?->configuration_set_id,
            'template_version_id' => $version?->id,
            'template_version' => $version?->version_number,
            'generated_at' => now(),
            'generated_by' => $user?->id,
        ]);
    }

    /**
     * The pinned version's body. A version may carry a body per locale (`locales.<locale>.html`); without
     * one the version's single body is used for every locale, so no HTML is duplicated per language.
     */
    private function templateHtml(DocumentSource $source, DocumentGeneration $generation): string
    {
        if ($generation->template_version_id === null) {
            return (string) $source->fallbackHtml;
        }
        $payload = ConfigurationVersion::query()->whereKey($generation->template_version_id)->value('payload');
        $payload = is_string($payload) ? json_decode($payload, true) : (array) $payload;

        return (string) ($payload['locales'][$generation->locale]['html'] ?? $payload['html'] ?? '');
    }

    private function latest(DocumentSource $source): ?DocumentGeneration
    {
        return $this->query($source)->orderByDesc('generated_at')->orderByDesc('created_at')->first();
    }

    /** @return Builder<DocumentGeneration> */
    private function query(DocumentSource $source): Builder
    {
        return DocumentGeneration::query()
            ->where('tenant_id', $source->tenantId)
            ->where('document_type', $source->documentType)
            ->where('source_entity_type', $source->sourceEntityType)
            ->where('source_entity_id', $source->sourceEntityId)
            ->when(
                $source->recipientPartnerId !== null,
                fn (Builder $q) => $q->where('recipient_partner_id', $source->recipientPartnerId),
                fn (Builder $q) => $q->whereNull('recipient_partner_id'),
            );
    }

    private function lockKey(DocumentSource $source): string
    {
        return implode(':', ['document_generation', $source->tenantId, $source->documentType, $source->sourceEntityId, $source->recipientPartnerId ?? '-']);
    }
}
