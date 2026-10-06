<?php

namespace Tests\Unit;

use App\Domain\Shared\Support\StatusLabels;
use App\Domain\Workflow\Support\WorkflowActionVerbs;
use PHPUnit\Framework\TestCase;

/**
 * i18n structural preparation: the backend status label and action verb registries mirror the
 * frontend ones exactly, and lookups never reformat a canonical code.
 */
class StatusLabelsTest extends TestCase
{
    /** @return array<string, array{key: string, en: string}> parsed `CODE: { key: '...', en: '...' }` entries */
    private function frontendEntries(string $file, string $constant): array
    {
        $source = file_get_contents(dirname(__DIR__, 3).'/frontend/src/i18n/'.$file);
        $start = strpos($source, "const {$constant}");
        $this->assertNotFalse($start, "{$constant} not found in {$file}");
        $block = substr($source, $start, strpos($source, '};', $start) - $start);
        preg_match_all("/^\s+([A-Z][A-Z0-9_]*): \{ key: '([^']+)', en: '((?:[^'\\\\]|\\\\.)*)' \},$/m", $block, $m, PREG_SET_ORDER);

        $entries = [];
        foreach ($m as [, $code, $key, $en]) {
            $entries[$code] = ['key' => $key, 'en' => stripslashes($en)];
        }

        return $entries;
    }

    public function test_status_registry_matches_the_frontend_registry(): void
    {
        $frontend = $this->frontendEntries('statusRegistry.ts', 'STATUS:');
        $this->assertGreaterThan(100, count($frontend));
        $this->assertSame($frontend, StatusLabels::STATUSES);
    }

    public function test_action_verbs_match_the_frontend_registry(): void
    {
        $frontend = $this->frontendEntries('workflowActionVerbs.ts', 'ACTION_VERBS:');
        $this->assertGreaterThan(20, count($frontend));
        $this->assertSame($frontend, WorkflowActionVerbs::BY_TARGET_STATUS);
    }

    public function test_every_action_verb_targets_a_known_status(): void
    {
        foreach (array_keys(WorkflowActionVerbs::BY_TARGET_STATUS) as $code) {
            $this->assertNotNull(StatusLabels::entry($code), $code);
        }
    }

    public function test_labels_come_from_the_registry_not_from_the_code(): void
    {
        $this->assertSame('QC Pending', StatusLabels::label('QC_PENDING'));
        $this->assertSame('Under Review', StatusLabels::label('UNDER_REVIEW'));
        $this->assertSame('Scrap', StatusLabels::label('SCRAPPED'));
        $this->assertSame('Active', StatusLabels::label('active'));
        $this->assertSame('status.underReview', StatusLabels::key('UNDER_REVIEW'));
    }

    public function test_domain_splits_issued(): void
    {
        $this->assertSame('status.document.issued', StatusLabels::key('ISSUED', 'document'));
        $this->assertSame('status.stock.issued', StatusLabels::key('ISSUED', 'stock'));
        $this->assertSame('status.document.issued', StatusLabels::key('ISSUED'), 'Without a domain, ISSUED is a document status.');
    }

    public function test_unknown_codes_are_returned_unchanged(): void
    {
        $this->assertSame('SOMETHING_NEW', StatusLabels::label('SOMETHING_NEW'));
        $this->assertNull(StatusLabels::key('SOMETHING_NEW'));
        $this->assertSame('', StatusLabels::label(null));
    }

    public function test_action_verbs_are_verbs_not_status_names(): void
    {
        $this->assertSame('Approve', WorkflowActionVerbs::label('APPROVED'));
        $this->assertSame('Cancel', WorkflowActionVerbs::label('CANCELLED'));
        $this->assertSame('Complete', WorkflowActionVerbs::label('COMPLETED'));
        $this->assertSame('Hold', WorkflowActionVerbs::label('ON_HOLD'));
        $this->assertSame('workflow.actionVerb.approved', WorkflowActionVerbs::key('APPROVED'));
        $this->assertNull(WorkflowActionVerbs::label('NOT_A_STATUS'));
    }
}
