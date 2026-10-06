<?php

namespace App\Domain\Configuration\Services;

use App\Domain\Shared\Support\Messages;

/**
 * Section 9/50: the publish-time gate — static AST-vs-registry check that
 * rejects (and blocks publish for) any variable or section a template
 * references that TemplateVariableRegistry does not know about for that
 * document type. findUnknownVariables() gives the same check as a
 * non-throwing list for live preview/editor feedback.
 */
class TemplateValidator
{
    public function __construct(
        private readonly TemplateParser $parser = new TemplateParser,
        private readonly TemplateVariableRegistry $registry = new TemplateVariableRegistry,
    ) {}

    public function validate(string $documentType, string $template): void
    {
        $unknown = $this->findUnknownVariables($documentType, $template);
        if (! empty($unknown)) {
            throw new TemplateValidationException(
                Messages::text('errors.notification.unknownVariables', ['variables' => implode(', ', $unknown)])
            );
        }
    }

    /**
     * @return string[] variable/section paths used in the template that are not in the registry
     */
    public function findUnknownVariables(string $documentType, string $template): array
    {
        $ast = $this->parser->parse($template);
        $definition = $this->registry->forDocumentType($documentType);

        $unknown = [];
        $this->walk($ast, $definition['scalars'], $definition['sections'], $definition['scalars'], $unknown);

        return array_values(array_unique($unknown));
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
