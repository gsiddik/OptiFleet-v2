<?php

namespace App\Domain\Configuration\Services;

/**
 * Section 9: the whole grammar a tenant template body can use — plain text,
 * {{variable.path}} substitution, and {{#section}}...{{/section}} /
 * {{^section}}...{{/section}} blocks. There is no operator, no function
 * call, no include/import, nothing that reaches outside the token stream
 * itself, so parsing a template can never do more than build this AST.
 */
class TemplateParser
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function parse(string $template): array
    {
        $tokens = $this->tokenize($template);
        [$nodes, $remaining] = $this->parseNodes($tokens, null);
        if (! empty($remaining)) {
            throw new TemplateParseException('Unexpected closing section tag.');
        }

        return $nodes;
    }

    private function tokenize(string $template): array
    {
        $pattern = '/\{\{(#|\^|\/)?\s*([a-zA-Z0-9_.]+)\s*\}\}/';
        $tokens = [];
        $offset = 0;

        while (preg_match($pattern, $template, $m, PREG_OFFSET_CAPTURE, $offset)) {
            $matchStart = $m[0][1];
            if ($matchStart > $offset) {
                $tokens[] = ['type' => 'text', 'value' => substr($template, $offset, $matchStart - $offset)];
            }

            $sigil = $m[1][0];
            $name = $m[2][0];
            $tokens[] = match ($sigil) {
                '#' => ['type' => 'section_open', 'name' => $name],
                '^' => ['type' => 'section_open', 'name' => $name, 'inverted' => true],
                '/' => ['type' => 'section_close', 'name' => $name],
                default => ['type' => 'var', 'path' => $name],
            };

            $offset = $matchStart + strlen($m[0][0]);
        }

        if ($offset < strlen($template)) {
            $tokens[] = ['type' => 'text', 'value' => substr($template, $offset)];
        }

        return $tokens;
    }

    /**
     * @return array{0: array<int, array<string, mixed>>, 1: array<int, array<string, mixed>>}
     */
    private function parseNodes(array $tokens, ?string $closingSection): array
    {
        $nodes = [];

        while ($tokens) {
            $token = array_shift($tokens);

            if ($token['type'] === 'text' || $token['type'] === 'var') {
                $nodes[] = $token;

                continue;
            }

            if ($token['type'] === 'section_close') {
                if ($token['name'] !== $closingSection) {
                    throw new TemplateParseException("Mismatched closing tag {{/{$token['name']}}}.");
                }

                return [$nodes, $tokens];
            }

            if ($token['type'] === 'section_open') {
                [$children, $tokens] = $this->parseNodes($tokens, $token['name']);
                $nodes[] = [
                    'type' => 'section',
                    'name' => $token['name'],
                    'inverted' => $token['inverted'] ?? false,
                    'children' => $children,
                ];
            }
        }

        if ($closingSection !== null) {
            throw new TemplateParseException("Unclosed section {{#{$closingSection}}}.");
        }

        return [$nodes, $tokens];
    }
}
