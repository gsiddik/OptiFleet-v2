<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Domain\AccessControl\Services\PermissionService;
use App\Domain\Configuration\Models\ConfigurationSet;
use App\Domain\Configuration\Models\ConfigurationVersion;
use App\Domain\Configuration\Services\ConfigurationService;
use App\Domain\Configuration\Services\DocumentNumberingService;
use App\Domain\Configuration\Services\DocumentTemplateRenderService;
use App\Domain\Configuration\Services\DocumentTypeRegistry;
use App\Domain\Configuration\Services\NumberingFormatValidator;
use App\Domain\Configuration\Services\TemplateDocumentCompiler;
use App\Domain\Configuration\Services\TemplateRenderer;
use App\Domain\Configuration\Services\TemplateValidator;
use App\Domain\Configuration\Services\TemplateVariableRegistry;
use App\Domain\Notification\Services\NotificationEventCatalog;
use App\Domain\Notification\Services\NotificationRuleService;
use App\Domain\Notification\Services\NotificationTemplateValidator;
use App\Domain\Workflow\Services\ConditionEvaluator;
use App\Domain\Workflow\Services\WorkflowActionCatalog;
use App\Domain\Workflow\Services\WorkflowCatalog;
use App\Domain\Workflow\Services\WorkflowDefinitionValidator;
use App\Domain\Workflow\Services\WorkflowEngine;
use App\Http\Controllers\Controller;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
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
    // TIRE_SCORING is retired (replaced by the Used Tire Inspection engine): its configurations stay
    // in the history, but none can be listed for editing, created, changed or published.
    private const TYPES = ['NUMBERING', 'TEMPLATE', 'WORKFLOW', 'NOTIFICATION'];

    public function __construct(
        private readonly ConfigurationService $configuration,
        private readonly NumberingFormatValidator $numberingValidator,
        private readonly TemplateValidator $templateValidator,
        private readonly WorkflowDefinitionValidator $workflowValidator,
        private readonly NotificationTemplateValidator $notificationValidator,
        private readonly DocumentNumberingService $numbering,
        private readonly DocumentTemplateRenderService $templates,
        private readonly WorkflowEngine $workflow,
        private readonly TemplateVariableRegistry $templateVariables,
        private readonly TenantContext $context,
        private readonly PermissionService $permissionService,
        private readonly DocumentTypeRegistry $documentTypes,
        private readonly TemplateDocumentCompiler $templateCompiler,
        private readonly NotificationEventCatalog $notificationEvents,
        private readonly TemplateRenderer $templateRenderer,
        private readonly WorkflowCatalog $workflowCatalog,
    ) {}

    /** Numbering Format Builder cards: what each token means and which setting it uses. */
    private const NUMBERING_TOKENS = [
        ['token' => 'DOC', 'label' => 'Document Code', 'description' => 'The document code you enter in "Doc Code Name" (e.g. PO).', 'parameter' => 'doc_code', 'parameter_label' => 'Doc Code Name'],
        ['token' => 'TENANT', 'label' => 'Tenant Initial', 'description' => 'Your company initial. Leave the Tenant Initial empty to use the tenant code.', 'parameter' => 'tenant_initial', 'parameter_label' => 'Tenant Initial'],
        ['token' => 'BRANCH', 'label' => 'Branch Initial', 'description' => 'The branch initial. Leave empty to use the code of the branch each document belongs to.', 'parameter' => 'branch_initial', 'parameter_label' => 'Branch Initial'],
        ['token' => 'WORKSHOP', 'label' => 'Workshop Initial', 'description' => 'The workshop initial. Leave empty to use the code of the workshop each document belongs to.', 'parameter' => 'workshop_initial', 'parameter_label' => 'Workshop Initial'],
        ['token' => 'WAREHOUSE', 'label' => 'Warehouse Initial', 'description' => 'The warehouse initial. Leave empty to use the code of the warehouse each document belongs to.', 'parameter' => 'warehouse_initial', 'parameter_label' => 'Warehouse Initial'],
        ['token' => 'YYYY', 'label' => 'Year (4 digits)', 'description' => 'Year of the document date, e.g. 2026.', 'parameter' => null, 'parameter_label' => null],
        ['token' => 'YY', 'label' => 'Year (2 digits)', 'description' => 'Year of the document date, e.g. 26.', 'parameter' => null, 'parameter_label' => null],
        ['token' => 'MMMM', 'label' => 'Month Name', 'description' => 'Full month name, e.g. OCTOBER.', 'parameter' => null, 'parameter_label' => null],
        ['token' => 'MMM', 'label' => 'Month (short)', 'description' => 'Short month name, e.g. OCT.', 'parameter' => null, 'parameter_label' => null],
        ['token' => 'MM', 'label' => 'Month (2 digits)', 'description' => 'Month number, e.g. 10.', 'parameter' => null, 'parameter_label' => null],
        ['token' => 'DD', 'label' => 'Day (2 digits)', 'description' => 'Day of the month, e.g. 05.', 'parameter' => null, 'parameter_label' => null],
        ['token' => 'SEQ', 'label' => 'Running Number', 'description' => 'Running number without leading zeros: 1, 2, 3 … 100.', 'parameter' => null, 'parameter_label' => null],
        ['token' => 'SEQ:N', 'label' => 'Running Number (fixed digits)', 'description' => 'Running number padded with zeros to the Sequential Digit, e.g. 000001 for 6 digits.', 'parameter' => 'sequence_digits', 'parameter_label' => 'Sequential Digit'],
        ['token' => 'ITEMTYPE', 'label' => 'Item Type Code', 'description' => 'Short code of the product item type (Product SKU only).', 'parameter' => null, 'parameter_label' => null],
        ['token' => 'CG', 'label' => 'Component Group Code', 'description' => 'Abbreviation of the product component group (Product SKU only).', 'parameter' => null, 'parameter_label' => null],
    ];

    private const MANAGE_PERMISSIONS = ['NUMBERING' => 'numbering.manage', 'TEMPLATE' => 'document_template.manage', 'WORKFLOW' => 'workflow.manage', 'NOTIFICATION' => 'document_template.manage'];

    private const PUBLISH_PERMISSIONS = ['NUMBERING' => 'numbering.publish', 'TEMPLATE' => 'document_template.publish', 'WORKFLOW' => 'workflow.publish', 'NOTIFICATION' => 'document_template.publish'];

    /** A retired type (Tire Scoring) is history only: it can no longer be changed or published. */
    private function managePermission(string $type): string
    {
        abort_unless(isset(self::MANAGE_PERMISSIONS[$type]), 422, 'This configuration type is retired and can no longer be changed.');

        return self::MANAGE_PERMISSIONS[$type];
    }

    private function publishPermission(string $type): string
    {
        abort_unless(isset(self::PUBLISH_PERMISSIONS[$type]), 422, 'This configuration type is retired and can no longer be changed.');

        return self::PUBLISH_PERMISSIONS[$type];
    }

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
            'NUMBERING' => $this->ok([
                // Kept for older clients: the raw token list.
                'tokens' => ['{DOC}', '{TENANT}', '{BRANCH}', '{WORKSHOP}', '{WAREHOUSE}', '{YYYY}', '{YY}', '{MMMM}', '{MMM}', '{MM}', '{DD}', '{ITEMTYPE}', '{CG}', '{SEQ}', '{SEQ:N}'],
                'document_types' => $this->documentTypes->forNumbering(),
                'token_definitions' => self::NUMBERING_TOKENS,
                'reset_rules' => [
                    ['value' => 'NEVER', 'label' => 'Never (one running number)'], ['value' => 'YEARLY', 'label' => 'Every year'],
                    ['value' => 'MONTHLY', 'label' => 'Every month'], ['value' => 'DAILY', 'label' => 'Every day'],
                ],
                'max_sequence_digits' => NumberingFormatValidator::MAX_SEQUENCE_DIGITS,
            ]),
            'TEMPLATE' => $this->ok([
                'document_types' => $this->templateVariables->documentTypes(),
                'document_type_options' => $this->documentTypes->forTemplates(),
                'variables' => $request->query('code') ? $this->templateVariables->forDocumentType($request->query('code')) : null,
                'catalog' => $request->query('code') ? $this->templateVariables->catalog($request->query('code')) : null,
            ]),
            'WORKFLOW' => $this->ok([
                'actions' => WorkflowActionCatalog::ACTIONS,
                'operators' => ConditionEvaluator::OPERATORS,
                // Visual Workflow Builder: the statuses this document can have and the statuses a
                // module action can move it into (null for a resource without a platform default).
                'catalog' => $request->query('code') ? $this->workflowCatalog->forResource((string) $request->query('code')) : null,
                'resource_types' => ConfigurationSet::query()->withoutGlobalScopes()->whereNull('tenant_id')
                    ->where('type', ConfigurationSet::TYPE_WORKFLOW)->orderBy('code')->get(['code', 'name'])
                    ->map(fn ($s) => ['code' => $s->code, 'name' => $s->name])->values(),
            ]),
            'NOTIFICATION' => $this->ok($this->notificationMetadata()),
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
        $this->requirePermission($this->managePermission($validated['type']));
        $this->assertKnownDocumentType($validated['type'], $validated['code']);

        $tenantId = $this->context->tenantId();
        $set = $this->configuration->findOrCreateSet($tenantId, $validated['type'], $validated['code'], 'TENANT', null, $validated['name']);
        $version = $this->configuration->createDraft($set, $this->normalizePayload($validated['type'], $validated['payload']), $this->context->user()->id, $validated['change_summary'] ?? null);

        return $this->ok($version->load('configurationSet'), 201);
    }

    public function updateDraft(ConfigurationVersion $version, Request $request)
    {
        $this->authorizeVersionScope($version);
        $this->requirePermission($this->managePermission($version->configurationSet->type));
        $validated = $request->validate(['payload' => ['required', 'array'], 'change_summary' => ['nullable', 'string']]);

        return $this->ok($this->configuration->updateDraft($version, $this->normalizePayload($version->configurationSet->type, $validated['payload']), $validated['change_summary'] ?? null));
    }

    public function publish(ConfigurationVersion $version)
    {
        $this->authorizeVersionScope($version);
        $set = $version->configurationSet;
        $this->requirePermission($this->publishPermission($set->type));

        $validator = match ($set->type) {
            'NUMBERING' => fn (array $p) => $this->numberingValidator->validate($p),
            'TEMPLATE' => fn (array $p) => $this->templateValidator->validate($set->code, $p['html'] ?? ''),
            'WORKFLOW' => fn (array $p) => $this->workflowValidator->validate($p, $set->code),
            'NOTIFICATION' => fn (array $p) => $this->notificationValidator->validate($set->code, $p),
        };

        return $this->ok($this->configuration->publish($version, $this->context->user()->id, $validator));
    }

    /**
     * Return to System Default: the tenant's own (custom) configuration of this document type
     * stops being used — its published version is archived (kept for history and audit, never
     * deleted), so the resolver falls back to the platform default again.
     */
    public function restoreDefault(ConfigurationSet $set)
    {
        $this->authorizeSetScope($set);
        $this->requirePermission($this->managePermission($set->type));
        abort_if($set->is_system || $set->tenant_id === null, 422, 'This is the system default already.');
        $hasDefault = ConfigurationSet::query()->withoutGlobalScopes()->whereNull('tenant_id')->where('type', $set->type)->where('code', $set->code)->where('scope_type', 'TENANT')->exists();
        abort_unless($hasDefault, 422, 'There is no system default for this document type to return to.');
        $published = $set->versions()->where('status', 'PUBLISHED')->first();
        abort_unless($published, 422, 'The system default is already in use.');

        return $this->ok($this->configuration->archive($published, $this->context->user()->id));
    }

    public function archive(ConfigurationVersion $version)
    {
        $this->authorizeVersionScope($version);
        $this->requirePermission($this->managePermission($version->configurationSet->type));

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
                null, null, null,
                max(1, (int) $request->input('sample_sequence', 1)),
                $request->filled('sample_date') ? CarbonImmutable::parse((string) $request->input('sample_date')) : null,
            )]),
            'TEMPLATE' => $this->ok(['html' => $this->templates->preview(
                (string) $request->input('code'),
                $request->has('editor') ? $this->templateCompiler->build((array) $request->input('editor', []))
                    : $this->templateCompiler->sanitize((string) $request->input('html', '')),
            )]),
            'WORKFLOW' => $this->ok($this->workflow->simulate(
                $this->resolveWorkflowVersionForPreview($request, $tenantId),
                (string) $request->input('from_status'),
                $this->context->user(),
                $tenantId,
                (array) $request->input('context', []),
            )),
            'NOTIFICATION' => $this->ok(['channels' => $this->previewNotification((string) $request->input('code'), (array) $request->input('payload', []))]),
            default => abort(422, 'Preview is not supported for this configuration type.'),
        };
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

    /** New Document Numbering / Template configurations are for document types the system generates. */
    private function assertKnownDocumentType(string $type, string $code): void
    {
        if ($type === 'NUMBERING') {
            abort_unless($this->documentTypes->supportsNumbering($code), 422, 'Choose a document type that supports document numbering.');
        }
        if ($type === 'TEMPLATE') {
            abort_unless($this->documentTypes->supportsTemplate($code), 422, 'Choose a document type that supports document templates.');
        }
        if ($type === 'NOTIFICATION') {
            abort_unless($this->notificationEvents->isKnownEvent($code) && ! $this->notificationEvents->isPlatformLocked($code), 422, 'Choose a notification event your company can configure.');
        }
    }

    /**
     * Notification configuration screens: events with their variables, channels, recipient
     * types (and what each one's identifier is), and the condition operators — the existing
     * NotificationRule / message template schema described for a form, never new semantics.
     */
    private function notificationMetadata(): array
    {
        $recipientLabels = [
            'EXPLICIT_USER' => ['Specific User', 'One user of your company.'],
            'ROLE' => ['Everyone with a Role', 'Every user who has the chosen role.'],
            'PERMISSION' => ['Everyone with a Permission', 'Every user whose role grants the chosen permission.'],
            'CUSTOM_EMAIL' => ['Email Address', 'Any email address (Email channel only).'],
            'BRANCH_MANAGER' => ['Branch Manager', 'Branch Managers who can access the branch of the record.'],
            'WORKSHOP_MANAGER' => ['Workshop Manager', 'Workshop Managers who can access the workshop of the record.'],
            'REQUESTER' => ['Requester', 'The user who requested the record.'],
            'APPROVER' => ['Approver', 'The user who approved the record.'],
            'ASSIGNED_MECHANIC' => ['Assigned Mechanic', 'The mechanic assigned to the record.'],
            'VEHICLE_PIC' => ['Vehicle PIC', 'The person in charge of the vehicle.'],
            'WAREHOUSE_PIC' => ['Warehouse PIC', 'The person in charge of the warehouse.'],
            'VENDOR_CONTACT' => ['Vendor Contact', "The vendor's contact email (Email channel only)."],
        ];
        $operators = [
            '=' => 'is', '!=' => 'is not', '>' => 'is greater than', '>=' => 'is at least', '<' => 'is less than', '<=' => 'is at most',
            'IN' => 'is one of', 'NOT_IN' => 'is none of', 'IS_NULL' => 'is empty', 'IS_NOT_NULL' => 'is not empty',
        ];

        return [
            'events' => array_map(fn (string $code) => [
                'code' => $code,
                'label' => $this->notificationEvents->label($code),
                'platform_locked' => $this->notificationEvents->isPlatformLocked($code),
                'variables' => $this->notificationEvents->variables($code),
            ], $this->notificationEvents->eventCodes()),
            'channels' => [['value' => 'IN_APP', 'label' => 'In-App'], ['value' => 'EMAIL', 'label' => 'Email']],
            'recipient_types' => array_map(fn (string $type) => [
                'value' => $type,
                'label' => $recipientLabels[$type][0],
                'description' => $recipientLabels[$type][1],
                'identifier' => NotificationRuleService::RECIPIENT_TYPES[$type],
            ], array_keys(NotificationRuleService::RECIPIENT_TYPES)),
            'operators' => array_map(fn (string $op) => [
                'value' => $op,
                'label' => $operators[$op],
                'needs_value' => ! in_array($op, ['IS_NULL', 'IS_NOT_NULL'], true),
                'multiple' => in_array($op, ['IN', 'NOT_IN'], true),
            ], ConditionEvaluator::OPERATORS),
            // Escalation re-checks only the record's current status (EscalationProcessor).
            'unresolved_fields' => [['key' => 'status', 'label' => 'Status of the record']],
        ];
    }

    /** A message template rendered with readable sample values, per channel. */
    private function previewNotification(string $eventCode, array $payload): array
    {
        abort_unless($this->notificationEvents->isKnownEvent($eventCode), 422, 'Unknown notification event.');
        $this->notificationValidator->validate($eventCode, $payload);
        $context = $this->notificationEvents->sampleContext($eventCode);
        $out = [];
        foreach ($payload['channels'] as $channel => $content) {
            $out[$channel] = [
                'subject' => isset($content['subject']) ? $this->templateRenderer->render((string) $content['subject'], $context) : null,
                'body' => $this->templateRenderer->render((string) $content['body'], $context),
            ];
        }

        return $out;
    }

    /**
     * Templates: the stored HTML is always built server-side — from the visual editor's document
     * when given (kept as `editor`, the editor's state), otherwise by sanitizing the HTML.
     */
    private function normalizePayload(string $type, array $payload): array
    {
        if ($type !== 'TEMPLATE') {
            return $payload;
        }
        if (isset($payload['editor']) && is_array($payload['editor'])) {
            return ['html' => $this->templateCompiler->build($payload['editor']['nodes'] ?? []), 'editor' => $payload['editor']];
        }

        return ['html' => $this->templateCompiler->sanitize((string) ($payload['html'] ?? ''))];
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
