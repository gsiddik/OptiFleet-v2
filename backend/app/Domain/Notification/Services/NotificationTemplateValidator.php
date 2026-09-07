<?php

namespace App\Domain\Notification\Services;

use App\Domain\Configuration\Services\TemplateParser;
use App\Domain\Configuration\Services\TemplateValidationException;

/**
 * Section 33: the same missing-variable publish gate as Document Templates
 * (Batch C's TemplateParser AST walk), applied to a notification message's
 * {subject, body} pair per channel against NotificationEventCatalog's
 * per-event variable allow-list instead of TemplateVariableRegistry's
 * per-document-type one — two different registries over the identical safe
 * parsing/rendering engine, so there is exactly one template grammar and
 * one execution model in the whole platform.
 */
class NotificationTemplateValidator
{
    public function __construct(
        private readonly TemplateParser $parser = new TemplateParser,
        private readonly NotificationEventCatalog $catalog = new NotificationEventCatalog,
    ) {}

    public function validate(string $eventCode, array $payload): void
    {
        $definition = $this->catalog->variableDefinition($eventCode);
        $channels = $payload['channels'] ?? [];

        if (empty($channels)) {
            throw new TemplateValidationException('A notification template must declare at least one channel.');
        }

        foreach ($channels as $channel => $content) {
            if (! in_array($channel, ['IN_APP', 'EMAIL'], true)) {
                throw new TemplateValidationException("Unsupported notification channel '{$channel}'.");
            }
            if (empty($content['body'])) {
                throw new TemplateValidationException("Channel '{$channel}' is missing a body.");
            }
            if ($channel === 'EMAIL' && empty($content['subject'])) {
                throw new TemplateValidationException("Channel '{$channel}' is missing a subject.");
            }

            $this->assertKnownVariables($content['body'], $definition);
            if (! empty($content['subject'])) {
                $this->assertKnownVariables($content['subject'], $definition);
            }
        }
    }

    private function assertKnownVariables(string $template, array $definition): void
    {
        $ast = $this->parser->parse($template);
        $unknown = [];
        $this->walk($ast, $definition['scalars'], $definition['sections'], $definition['scalars'], $unknown);

        if (! empty($unknown)) {
            throw new TemplateValidationException('Template references unknown variable(s): '.implode(', ', array_unique($unknown)));
        }
    }

    private function walk(array $nodes, array $topScalars, array $sectionsMap, array $currentScalars, array &$unknown): void
    {
        foreach ($nodes as $node) {
            if ($node['type'] === 'var') {
                if (! in_array($node['path'], $currentScalars, true) && ! in_array($node['path'], $topScalars, true)) {
                    $unknown[] = $node['path'];
                }

                continue;
            }

            if ($node['type'] === 'section') {
                $name = $node['name'];
                $isRepeating = array_key_exists($name, $sectionsMap);
                $isConditionalScalar = in_array($name, $topScalars, true) || in_array($name, $currentScalars, true);
                if (! $isRepeating && ! $isConditionalScalar) {
                    $unknown[] = $name;
                }
                $childScalars = $isRepeating ? $sectionsMap[$name] : $currentScalars;
                $this->walk($node['children'], $topScalars, $sectionsMap, $childScalars, $unknown);
            }
        }
    }
}
