<?php

namespace App\Domain\Configuration\Services;

/**
 * Section 9/51: executes the AST produced by TemplateParser against a
 * plain-array context — a pure tree walk of variable lookups and array
 * iteration. There is no eval(), no PHP/JS execution path, and no way for
 * template text to invoke anything beyond "read this key out of the array I
 * was given" — the context array is the entire attack surface, and callers
 * build it explicitly (never pass an Eloquent model or ->toArray() through
 * unfiltered) so a template can only ever see what its caller chose to
 * expose. Every scalar is HTML-escaped on output.
 */
class TemplateRenderer
{
    public function __construct(private readonly TemplateParser $parser = new TemplateParser) {}

    public function render(string $template, array $context): string
    {
        $ast = $this->parser->parse($template);

        return $this->renderNodes($ast, [$context]);
    }

    private function renderNodes(array $nodes, array $contextStack): string
    {
        $out = '';
        foreach ($nodes as $node) {
            $out .= match ($node['type']) {
                'text' => $node['value'],
                'var' => $this->renderVar($node['path'], $contextStack),
                'section' => $this->renderSection($node, $contextStack),
            };
        }

        return $out;
    }

    private function renderVar(string $path, array $contextStack): string
    {
        $value = $this->lookup($path, $contextStack);
        if (is_array($value)) {
            return '';
        }

        return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
    }

    private function renderSection(array $node, array $contextStack): string
    {
        $value = $this->lookup($node['name'], $contextStack);
        $isFalsy = $value === null || $value === false || $value === [] || $value === '' || $value === 0;

        if ($node['inverted']) {
            return $isFalsy ? $this->renderNodes($node['children'], $contextStack) : '';
        }

        if (is_array($value) && array_is_list($value)) {
            $out = '';
            foreach ($value as $item) {
                $itemContext = is_array($item) ? $item : ['.' => $item];
                $out .= $this->renderNodes($node['children'], [...$contextStack, $itemContext]);
            }

            return $out;
        }

        if ($isFalsy) {
            return '';
        }

        $nextStack = is_array($value) ? [...$contextStack, $value] : $contextStack;

        return $this->renderNodes($node['children'], $nextStack);
    }

    private function lookup(string $path, array $contextStack): mixed
    {
        $segments = explode('.', $path);

        for ($i = count($contextStack) - 1; $i >= 0; $i--) {
            $value = $contextStack[$i];
            $ok = true;
            foreach ($segments as $segment) {
                if (is_array($value) && array_key_exists($segment, $value)) {
                    $value = $value[$segment];
                } else {
                    $ok = false;
                    break;
                }
            }
            if ($ok) {
                return $value;
            }
        }

        return null;
    }
}
