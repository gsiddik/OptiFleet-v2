<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Domain\AccessControl\Services\PermissionService;
use App\Domain\Configuration\Models\ConfigurationSet;
use App\Domain\Configuration\Models\ConfigurationVersion;
use App\Domain\Configuration\Services\ConfigurationService;
use App\Domain\Configuration\Services\DocumentNumberingService;
use App\Domain\Configuration\Services\DocumentTemplateRenderService;
use App\Domain\Configuration\Services\NumberingFormatValidator;
use App\Domain\Configuration\Services\TemplateValidator;
use App\Domain\Configuration\Services\TemplateVariableRegistry;
use App\Domain\Notification\Services\NotificationTemplateValidator;
use App\Domain\Tire\Services\TireException;
use App\Domain\Tire\Services\TireScoringConfigurationValidator;
use App\Domain\Tire\Services\TireScoringService;
use App\Domain\Workflow\Services\ConditionEvaluator;
use App\Domain\Workflow\Services\WorkflowActionCatalog;
use App\Domain\Workflow\Services\WorkflowDefinitionValidator;
use App\Domain\Workflow\Services\WorkflowEngine;
use App\Http\Controllers\Controller;
use App\Support\TenantContext;
use Illuminate\Http\Request;

/**
 * Section 39-44: a single generic control surface over the versioned
 * configuration types (NUMBERING/TEMPLATE/WORKFLOW/NOTIFICATION message
 * templates) — every one of them is a ConfigurationSet/ConfigurationVersion
 * pair (Batch A), so list/show/draft/publish/archive/history need exactly
 * one implementation each; only publish() and preview() branch by type,
 * because that's the only place the four subsystems' rules actually
 * differ (which validator applies, what "preview" means). Reuses every
 * publish-time validator and render/resolve service already built and
 * tested in Batches B-F rather than re-implementing any of them.
 */
class ConfigurationController extends Controller
{
    private const TYPES = ['NUMBERING', 'TEMPLATE', 'WORKFLOW', 'NOTIFICATION', 'TIRE_SCORING'];

    public function __construct(
        private readonly ConfigurationService $configuration,
        private readonly NumberingFormatValidator $numberingValidator,
        private readonly TemplateValidator $templateValidator,
        private readonly WorkflowDefinitionValidator $workflowValidator,
        private readonly NotificationTemplateValidator $notificationValidator,
        private readonly TireScoringConfigurationValidator $tireScoringValidator,
        private readonly TireScoringService $tireScoring,
        private readonly DocumentNumberingService $numbering,
        private readonly DocumentTemplateRenderService $templates,
        private readonly WorkflowEngine $workflow,
        private readonly TemplateVariableRegistry $templateVariables,
        private readonly TenantContext $context,
        private readonly PermissionService $permissionService,
    ) {}

    private const MANAGE_PERMISSIONS = ['NUMBERING' => 'numbering.manage', 'TEMPLATE' => 'document_template.manage', 'WORKFLOW' => 'workflow.manage', 'NOTIFICATION' => 'document_template.manage', 'TIRE_SCORING' => 'tire_scoring_configuration.manage'];

    private const PUBLISH_PERMISSIONS = ['NUMBERING' => 'numbering.publish', 'TEMPLATE' => 'document_template.publish', 'WORKFLOW' => 'workflow.publish', 'NOTIFICATION' => 'document_template.publish', 'TIRE_SCORING' => 'tire_scoring_configuration.publish'];

    private function requirePermission(string $permission): void
    {
        abort_unless($this->permissionService->userHasPermission($this->context->user(), $permission, $this->context->tenantId()), 403, "Missing required permission: {$permission}");
    }

    /**
     * Section 40-41: the token/variable/operator/action pickers each editor
     * UI needs — static, read-only metadata, never DB access.
     */
    public function metadata(Request $request)
    {
        $type = strtoupper((string) $request->query('type'));

        return match ($type) {
            'NUMBERING' => $this->ok(['tokens' => ['{DOC}', '{TENANT}', '{BRANCH}', '{WORKSHOP}', '{WAREHOUSE}', '{YYYY}', '{YY}', '{MM}', '{DD}', '{ITEMTYPE}', '{CG}', '{SEQ}', '{SEQ:N}']]),
            'TEMPLATE' => $this->ok([
                'document_types' => $this->templateVariables->documentTypes(),
                'variables' => $request->query('code') ? $this->templateVariables->forDocumentType($request->query('code')) : null,
            ]),
            'WORKFLOW' => $this->ok(['actions' => WorkflowActionCatalog::ACTIONS, 'operators' => ConditionEvaluator::OPERATORS]),
            default => abort(422, 'Unknown metadata type.'),
        };
    }

    public function index(Request $request)
    {
        $type = strtoupper((string) $request->query('type'));
        abort_unless(in_array($type, self::TYPES, true), 422, 'Invalid configuration type.');

        $sets = ConfigurationSet::query()
            ->where('type', $type)
            ->where('scope_type', 'TENANT')
            ->with(['versions' => fn ($q) => $q->orderByDesc('version_number')])
            ->orderBy('code')
            ->get();

        return $this->ok($sets);
    }

    public function show(ConfigurationSet $set)
    {
        $this->authorizeSetScope($set);

        return $this->ok($set->load(['versions' => fn ($q) => $q->orderByDesc('version_number')]));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'type' => ['required', 'string', 'in:'.implode(',', self::TYPES)],
            'code' => ['required', 'string'],
            'name' => ['required', 'string'],
            'payload' => ['required', 'array'],
            'change_summary' => ['nullable', 'string'],
        ]);
        $this->requirePermission(self::MANAGE_PERMISSIONS[$validated['type']]);

        $tenantId = $this->context->tenantId();
        $set = $this->configuration->findOrCreateSet($tenantId, $validated['type'], $validated['code'], 'TENANT', null, $validated['name']);
        $version = $this->configuration->createDraft($set, $validated['payload'], $this->context->user()->id, $validated['change_summary'] ?? null);

        return $this->ok($version->load('configurationSet'), 201);
    }

    public function updateDraft(ConfigurationVersion $version, Request $request)
    {
        $this->authorizeVersionScope($version);
        $this->requirePermission(self::MANAGE_PERMISSIONS[$version->configurationSet->type]);
        $validated = $request->validate(['payload' => ['required', 'array'], 'change_summary' => ['nullable', 'string']]);

        return $this->ok($this->configuration->updateDraft($version, $validated['payload'], $validated['change_summary'] ?? null));
    }

    public function publish(ConfigurationVersion $version)
    {
        $this->authorizeVersionScope($version);
        $set = $version->configurationSet;
        $this->requirePermission(self::PUBLISH_PERMISSIONS[$set->type]);

        $validator = match ($set->type) {
            'NUMBERING' => fn (array $p) => $this->numberingValidator->validate($p),
            'TEMPLATE' => fn (array $p) => $this->templateValidator->validate($set->code, $p['html'] ?? ''),
            'WORKFLOW' => fn (array $p) => $this->workflowValidator->validate($p),
            'NOTIFICATION' => fn (array $p) => $this->notificationValidator->validate($set->code, $p),
            // R2: shape validation (incl. the required legal_restrictions/casing_eligibility/
            // lifecycle_limits keys) runs first, so an invalid payload is still rejected for
            // its own specific reason even when maker and checker happen to be the same user;
            // only once the payload is genuinely valid does the maker-checker self-check apply
            // — publish is this framework's "activate" action, so it is the one that must be
            // gated, mirroring UsedPartDispositionService's/TireScoringService::finalize()'s
            // established explicit-self-check pattern (WorkflowApprovalService itself does not
            // enforce this generically).
            'TIRE_SCORING' => function (array $p) use ($set, $version) {
                $this->tireScoringValidator->validate($set, $p);
                if ($version->created_by !== null && $version->created_by === $this->context->user()->id) {
                    throw new TireException('The maker who drafted this Tire Scoring configuration cannot also publish (activate) it — a different authorized user must verify and publish.');
                }
            },
        };

        return $this->ok($this->configuration->publish($version, $this->context->user()->id, $validator));
    }

    public function archive(ConfigurationVersion $version)
    {
        $this->authorizeVersionScope($version);
        $this->requirePermission(self::MANAGE_PERMISSIONS[$version->configurationSet->type]);

        return $this->ok($this->configuration->archive($version, $this->context->user()->id));
    }

    /**
     * Section 44: one centralized history view across all four
     * subsystems — type/name/version/status/created-by/published-by/
     * published-at/archived-at/change-summary.
     */
    public function history(Request $request)
    {
        $tenantId = $this->context->tenantId();
        $type = $request->query('type') ? strtoupper($request->query('type')) : null;

        $query = ConfigurationVersion::query()
            ->whereHas('configurationSet', function ($q) use ($tenantId, $type) {
                $q->where('tenant_id', $tenantId);
                if ($type) {
                    $q->where('type', $type);
                }
            })
            ->with('configurationSet')
            ->orderByDesc('updated_at');

        return $this->paginated($query->paginate($request->integer('per_page', 30)), fn (ConfigurationVersion $v) => [
            'id' => $v->id,
            'type' => $v->configurationSet->type,
            'code' => $v->configurationSet->code,
            'name' => $v->configurationSet->name,
            'version_number' => $v->version_number,
            'status' => $v->status,
            'created_by' => $v->created_by,
            'published_by' => $v->published_by,
            'published_at' => $v->published_at,
            'archived_at' => $v->archived_at,
            'change_summary' => $v->change_summary,
        ]);
    }

    /**
     * Section 11/26/41: type-specific, non-mutating preview — a formatted
     * sample document number, a rendered document template, or a workflow
     * simulation. Never touches published/effective configuration state.
     */
    public function preview(Request $request)
    {
        $type = strtoupper((string) $request->input('type'));
        $tenantId = $this->context->tenantId();
        if ($type === 'WORKFLOW') {
            $this->requirePermission('workflow.simulate');
        }

        return match ($type) {
            'NUMBERING' => $this->ok(['preview' => $this->numbering->preview(
                (array) $request->input('payload', []),
                (string) $request->input('code'),
                $tenantId,
            )]),
            'TEMPLATE' => $this->ok(['html' => $this->templates->preview(
                (string) $request->input('code'),
                (string) $request->input('html', ''),
            )]),
            'WORKFLOW' => $this->ok($this->workflow->simulate(
                $this->resolveWorkflowVersionForPreview($request, $tenantId),
                (string) $request->input('from_status'),
                $this->context->user(),
                $tenantId,
                (array) $request->input('context', []),
            )),
            'TIRE_SCORING' => $this->ok($this->previewTireScoring($request, $tenantId)),
            default => abort(422, 'Preview is not supported for this configuration type.'),
        };
    }

    /**
     * R2 §18: dry-run against a DRAFT (or arbitrary) payload — validates its
     * shape first (the same validator publish() would run), then computes
     * what calculate() would produce for the supplied representative/test
     * measurements. NEVER persists a TireScoringResult and never touches
     * any Tire/inspection/retread/repair row. If the scoring type already
     * has a currently PUBLISHED (active) version, also computes the same
     * inputs against it so the two can be compared side by side.
     */
    private function previewTireScoring(Request $request, string $tenantId): array
    {
        $code = strtoupper((string) $request->input('code'));
        abort_unless(in_array($code, ['REPAIR', 'RETREAD'], true), 422, 'code must be REPAIR or RETREAD.');
        $payload = (array) $request->input('payload', []);

        $dummySet = new ConfigurationSet(['tenant_id' => $tenantId, 'type' => 'TIRE_SCORING', 'code' => $code, 'scope_type' => 'TENANT']);
        $this->tireScoringValidator->validate($dummySet, $payload);

        $referenceTreadDepth = $request->input('reference_tread_depth_mm') !== null ? (float) $request->input('reference_tread_depth_mm') : null;
        $measuredTreadDepth = $request->input('measured_tread_depth_mm') !== null ? (float) $request->input('measured_tread_depth_mm') : null;
        $kaScore = $request->input('ka_score') !== null ? (float) $request->input('ka_score') : null;
        $criticalSafetyFail = (bool) $request->boolean('critical_safety_fail');

        $draftResult = $this->tireScoring->dryRun($payload, $referenceTreadDepth, $measuredTreadDepth, $kaScore, $criticalSafetyFail);

        $activeVersion = null;
        $activeResult = null;
        $active = ConfigurationSet::query()
            ->where('tenant_id', $tenantId)->where('type', 'TIRE_SCORING')->where('code', $code)
            ->with(['versions' => fn ($q) => $q->where('status', 'PUBLISHED')])->first();
        $activePublished = $active?->versions->first();
        if ($activePublished) {
            $activeVersion = $activePublished->id;
            $activeResult = $this->tireScoring->dryRun($activePublished->payload, $referenceTreadDepth, $measuredTreadDepth, $kaScore, $criticalSafetyFail);
        }

        return [
            'draft_result' => $draftResult,
            'active_configuration_version_id' => $activeVersion,
            'active_result' => $activeResult,
            'changed_from_active' => $activeResult !== null && $activeResult !== $draftResult,
            'note' => 'Dry-run only — no Tire, inspection, or scoring result was created or changed.',
        ];
    }

    private function resolveWorkflowVersionForPreview(Request $request, string $tenantId): ConfigurationVersion
    {
        if ($versionId = $request->input('version_id')) {
            $version = ConfigurationVersion::query()->findOrFail($versionId);
            $this->authorizeVersionScope($version);

            return $version;
        }

        $version = $this->workflow->resolveEffective((string) $request->input('code'), $tenantId);
        abort_unless($version, 404, 'No published workflow found for this resource type.');

        return $version;
    }

    private function authorizeSetScope(ConfigurationSet $set): void
    {
        abort_unless($set->tenant_id === $this->context->tenantId(), 404);
    }

    private function authorizeVersionScope(ConfigurationVersion $version): void
    {
        // The configurationSet relation is itself tenant-scoped (BelongsToTenantOrPlatform),
        // so a cross-tenant lookup would silently come back null rather than someone
        // else's set — load it explicitly, unscoped, so the check below is the one
        // and only thing standing between a version and cross-tenant access.
        $set = ConfigurationSet::query()->withoutGlobalScopes()->find($version->configuration_set_id);
        abort_unless($set, 404);
        $this->authorizeSetScope($set);
    }
}
