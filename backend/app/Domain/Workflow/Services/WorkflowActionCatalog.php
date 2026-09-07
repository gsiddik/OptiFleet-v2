<?php

namespace App\Domain\Workflow\Services;

/**
 * Section 24: the platform-owned whitelist of side effects a workflow
 * transition may declare. A tenant configures WHICH of these run on which
 * transition (and with what params) — it can never invent a new one or
 * supply arbitrary code, so "configuring a workflow action" can only ever
 * select from this fixed, platform-implemented catalog.
 */
class WorkflowActionCatalog
{
    public const ACTIONS = [
        'SEND_NOTIFICATION',
        'ASSIGN_USER',
        'CREATE_TASK',
        'GENERATE_DOCUMENT_NUMBER',
        'GENERATE_DOCUMENT',
        'RESERVE_STOCK',
        'RELEASE_RESERVATION',
        'CREATE_WORK_ORDER',
        'UPDATE_RESOURCE_STATUS',
        'CREATE_APPROVAL_RECORD',
    ];

    public function isValid(string $actionCode): bool
    {
        return in_array($actionCode, self::ACTIONS, true);
    }
}
