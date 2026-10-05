<?php

namespace App\Domain\Workflow\Services;

/**
 * Section 27: the publish-time gate for a workflow definition payload —
 * {statuses: [...], transitions: [...], layout?: ...}. WorkflowGraphAnalyzer checks the graph
 * (unique status codes, a start status, known statuses / permissions / actions / operators,
 * valid approval rules, no duplicate or self transitions, every status reachable from a start
 * status) and, for a resource with a platform default, that every status is one the document can
 * have and every transition targets a status some module action can move it into. Status-graph
 * cycles (e.g. QC_PENDING -> REWORK -> IN_PROGRESS) are explicitly NOT an error. The first error
 * is reported; warnings never block publishing. `layout` (node positions) is presentation only and
 * is never validated or read by the runtime.
 */
class WorkflowDefinitionValidator
{
    public function __construct(
        private readonly WorkflowGraphAnalyzer $analyzer = new WorkflowGraphAnalyzer,
        private readonly WorkflowCatalog $catalog = new WorkflowCatalog,
    ) {}

    public function validate(array $payload, ?string $resourceType = null): void
    {
        $result = $this->analyzer->analyze($payload, $resourceType !== null ? $this->catalog->forResource($resourceType) : null);
        if ($result['errors'] !== []) {
            throw new WorkflowValidationException($result['errors'][0]['message']);
        }
    }
}
