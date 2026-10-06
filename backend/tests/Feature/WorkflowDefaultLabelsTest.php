<?php

namespace Tests\Feature;

use App\Domain\Configuration\Models\ConfigurationSet;
use App\Domain\Shared\Support\StatusLabels;
use App\Domain\Workflow\Support\WorkflowActionVerbs;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * i18n structural preparation (runtime label mapping): the seeded platform-default workflows take
 * status display names and action labels from the registries — never `ucwords` of the code — while
 * every canonical status code, action code and transition stays unchanged.
 */
class WorkflowDefaultLabelsTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array<string, mixed>> resource type => published payload */
    private function publishedDefaults(): array
    {
        return ConfigurationSet::query()->whereNull('tenant_id')->where('type', ConfigurationSet::TYPE_WORKFLOW)->get()
            ->mapWithKeys(fn (ConfigurationSet $set) => [$set->code => $set->publishedVersion()?->payload])
            ->filter()
            ->all();
    }

    public function test_every_seeded_status_uses_the_registry_display_name(): void
    {
        $defaults = $this->publishedDefaults();
        $this->assertGreaterThanOrEqual(10, count($defaults));

        foreach ($defaults as $resource => $payload) {
            foreach ($payload['statuses'] as $status) {
                $this->assertNotNull(StatusLabels::entry($status['code']), "{$resource}: {$status['code']} missing from StatusLabels");
                $this->assertSame(StatusLabels::label($status['code']), $status['display_name'], "{$resource}: {$status['code']}");
            }
        }
        $wo = collect($defaults['work_order']['statuses'])->keyBy('code');
        $this->assertSame('QC Pending', $wo['QC_PENDING']['display_name']);
    }

    public function test_seeded_action_labels_are_verbs_and_codes_are_unchanged(): void
    {
        foreach ($this->publishedDefaults() as $resource => $payload) {
            foreach ($payload['transitions'] as $t) {
                $this->assertMatchesRegularExpression('/^[A-Z][A-Z0-9_]+$/', $t['to_status']);
                $this->assertMatchesRegularExpression('/^[a-z][a-z0-9_]*$/', $t['action_code']);
                if ($t['action_code'] === strtolower($t['to_status'])) {
                    // Generated default transition: the label is the registered verb, not the status name.
                    $this->assertSame(WorkflowActionVerbs::label($t['to_status']), $t['action_label'], "{$resource}: {$t['from_status']}->{$t['to_status']}");
                } else {
                    $this->assertNotSame('', trim((string) $t['action_label']), "{$resource}: {$t['action_code']}");
                }
            }
        }
        $mr = collect($this->publishedDefaults()['maintenance_request']['transitions']);
        $approve = $mr->firstWhere('to_status', 'APPROVED');
        $this->assertSame('approved', $approve['action_code']);
        $this->assertSame(WorkflowActionVerbs::label('APPROVED'), $approve['action_label']);
        $this->assertSame('Approve', $approve['action_label']);
    }

    public function test_seeded_set_names_are_unchanged(): void
    {
        $names = ConfigurationSet::query()->whereNull('tenant_id')->where('type', ConfigurationSet::TYPE_WORKFLOW)->pluck('name', 'code');
        $this->assertSame('Work Order Workflow', $names['work_order']);
        $this->assertSame('Maintenance Request Workflow', $names['maintenance_request']);
        $this->assertSame('Used Part Disposition Workflow', $names['used_part_disposition']);
    }
}
