<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Domain\Notification\Models\NotificationRule;
use App\Domain\Notification\Services\NotificationEventCatalog;
use App\Domain\Notification\Services\NotificationRuleService;
use App\Http\Controllers\Controller;
use App\Support\TenantContext;
use Illuminate\Http\Request;

/**
 * Section 40: unlike numbering/template/workflow, a NotificationRule is
 * not versioned (Batch F) — it is a plain, always-current row a tenant
 * activates/edits/deactivates directly. NotificationRuleService is the
 * single place that enforces platform-locked events and is_system rows
 * can never be mutated from here.
 */
class NotificationRuleController extends Controller
{
    public function __construct(
        private readonly NotificationRuleService $rules,
        private readonly NotificationEventCatalog $catalog,
        private readonly TenantContext $context,
    ) {}

    public function index(Request $request)
    {
        $tenantId = $this->context->tenantId();
        $query = NotificationRule::query()
            ->where(fn ($q) => $q->where('tenant_id', $tenantId)->orWhereNull('tenant_id'));

        if ($event = $request->query('event_code')) {
            $query->where('event_code', $event);
        }

        return $this->ok($query->orderBy('event_code')->get());
    }

    public function events()
    {
        return $this->ok(array_map(fn ($code) => [
            'code' => $code,
            'platform_locked' => $this->catalog->isPlatformLocked($code),
            'variables' => $this->catalog->variableDefinition($code),
        ], $this->catalog->eventCodes()));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'event_code' => ['required', 'string'],
            'name' => ['required', 'string'],
            'condition_set' => ['nullable', 'array'],
            'recipient_rules' => ['required', 'array', 'min:1'],
            'channels' => ['required', 'array', 'min:1'],
            'escalation' => ['nullable', 'array'],
        ]);

        $rule = $this->rules->create(
            $this->context->tenantId(),
            $validated['event_code'],
            $validated['name'],
            $validated['recipient_rules'],
            $validated['channels'],
            $validated['condition_set'] ?? null,
            $validated['escalation'] ?? null,
        );

        return $this->ok($rule, 201);
    }

    public function update(NotificationRule $rule, Request $request)
    {
        $this->authorizeScope($rule);
        $validated = $request->validate([
            'name' => ['sometimes', 'string'],
            'condition_set' => ['sometimes', 'nullable', 'array'],
            'recipient_rules' => ['sometimes', 'array', 'min:1'],
            'channels' => ['sometimes', 'array', 'min:1'],
            'escalation' => ['sometimes', 'nullable', 'array'],
        ]);

        return $this->ok($this->rules->update($rule, $validated));
    }

    public function activate(NotificationRule $rule)
    {
        $this->authorizeScope($rule);

        return $this->ok($this->rules->setActive($rule, true));
    }

    public function deactivate(NotificationRule $rule)
    {
        $this->authorizeScope($rule);

        return $this->ok($this->rules->setActive($rule, false));
    }

    /**
     * Route binding already applies TenantOrPlatformScope (own tenant's
     * rules + shared platform rules) — this only re-confirms the row isn't
     * another tenant's. Whether a *mutation* is actually allowed on a
     * platform/is_system rule is NotificationRuleService::assertMutable()'s
     * job, not this method's.
     */
    private function authorizeScope(NotificationRule $rule): void
    {
        abort_unless($rule->tenant_id === null || $rule->tenant_id === $this->context->tenantId(), 404);
    }
}
