<?php

namespace App\Http\Controllers\Concerns;

use App\Domain\DocumentGeneration\Models\DocumentGeneration;
use App\Domain\DocumentGeneration\Services\DocumentGenerationService;
use App\Domain\DocumentGeneration\Support\DocumentLocale;
use App\Domain\DocumentGeneration\Support\DocumentSource;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * The three print endpoints every printable document shares, once its controller has checked tenant,
 * permission and data scope and described the document as a DocumentSource:
 *
 * - GET  …/print[?generation=<id>][&locale=]  reprint: the given generation, else the latest one. Only
 *   the very first print of a document creates a generation, in `locale` or the user → tenant → en default.
 * - GET  …/print/generations                  the document's generations, newest first.
 * - POST …/print/generations {locale?}         Generate New Version.
 */
trait PrintsDocuments
{
    protected function printDocument(Request $request, DocumentSource $source): Response
    {
        $validated = $request->validate([
            'generation' => ['nullable', 'uuid'],
            'locale' => ['nullable', Rule::in(DocumentLocale::SUPPORTED)],
        ]);
        $documents = app(DocumentGenerationService::class);
        $generation = $documents->forPrint($source, $validated['generation'] ?? null, $validated['locale'] ?? null, app(TenantContext::class)->user());

        return $documents->pdfResponse($source, $generation);
    }

    protected function documentGenerations(DocumentSource $source): JsonResponse
    {
        $history = app(DocumentGenerationService::class)->history($source);
        $count = $history->count();

        return $this->ok($history->values()->map(fn (DocumentGeneration $g, int $i) => $this->presentGeneration($g, $count - $i))->all());
    }

    protected function generateDocument(Request $request, DocumentSource $source): JsonResponse
    {
        $validated = $request->validate(['locale' => ['nullable', Rule::in(DocumentLocale::SUPPORTED)]]);
        $documents = app(DocumentGenerationService::class);
        $generation = $documents->generate($source, $validated['locale'] ?? null, app(TenantContext::class)->user());

        return $this->ok($this->presentGeneration($generation, $documents->history($source)->count()), 201);
    }

    /** @return array<string, mixed> */
    private function presentGeneration(DocumentGeneration $generation, int $sequence): array
    {
        return [
            'id' => $generation->id,
            'sequence' => $sequence,
            'document_type' => $generation->document_type,
            'locale' => $generation->locale,
            'template_id' => $generation->template_id,
            'template_version_id' => $generation->template_version_id,
            'template_version' => $generation->template_version,
            'generated_at' => $generation->generated_at?->toIso8601String(),
            'generated_by' => $generation->generated_by,
        ];
    }
}
