<?php

namespace App\Domain\Configuration\Services;

use DOMDocument;
use DOMElement;
use DOMNode;

/**
 * Configuration → Document Template: turns the visual editor's document (a JSON tree of
 * elements, text, variables and repeating / conditional blocks) into the HTML template the
 * existing renderer prints, and sanitizes any HTML a template carries. The frontend's HTML is
 * never trusted: the stored HTML is always (re)built here from an allowlist of tags, attributes
 * and CSS properties — scripts, event handlers, links, URLs and anything unknown are dropped.
 *
 * Template tokens are the existing grammar only ({{path}}, {{#name}}…{{/name}},
 * {{^name}}…{{/name}}); the variables themselves are validated against TemplateVariableRegistry
 * by TemplateValidator.
 */
class TemplateDocumentCompiler
{
    public const TAGS = [
        'div', 'p', 'span', 'br', 'hr', 'strong', 'b', 'em', 'i', 'u', 's', 'small', 'sub', 'sup', 'blockquote',
        'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'ul', 'ol', 'li',
        'table', 'thead', 'tbody', 'tfoot', 'tr', 'th', 'td', 'caption', 'colgroup', 'col',
    ];

    private const VOID = ['br', 'hr', 'col'];

    private const ATTRIBUTES = ['style', 'colspan', 'rowspan', 'border', 'cellpadding', 'cellspacing', 'align', 'valign', 'width', 'height'];

    private const CSS_PROPERTIES = [
        'font-family', 'font-size', 'font-weight', 'font-style', 'text-align', 'text-decoration', 'text-transform', 'color',
        'background-color', 'border', 'border-top', 'border-bottom', 'border-left', 'border-right', 'border-collapse', 'border-color',
        'border-style', 'border-width', 'border-radius', 'padding', 'padding-top', 'padding-bottom', 'padding-left', 'padding-right',
        'margin', 'margin-top', 'margin-bottom', 'margin-left', 'margin-right', 'width', 'min-width', 'max-width', 'height',
        'display', 'justify-content', 'align-items', 'flex', 'flex-direction', 'flex-wrap', 'gap', 'vertical-align', 'line-height',
        'white-space', 'letter-spacing', 'list-style-type', 'page-break-before', 'page-break-after', 'page-break-inside',
    ];

    private const NAME = '/^[a-zA-Z0-9_]+(\.[a-zA-Z0-9_]+)*$/';

    // ------------------------------------------------------------------ editor tree → HTML

    /**
     * @param  array<int, array<string, mixed>>  $nodes  the editor document's top-level nodes
     */
    public function build(array $nodes): string
    {
        return $this->sanitize($this->buildNodes($nodes, 0));
    }

    private function buildNodes(array $nodes, int $depth): string
    {
        if ($depth > 40) {
            throw new TemplateValidationException('The document is nested too deeply.');
        }
        $html = '';
        foreach ($nodes as $node) {
            $html .= $this->buildNode(is_array($node) ? $node : [], $depth);
        }

        return $html;
    }

    private function buildNode(array $node, int $depth): string
    {
        return match ($node['t'] ?? null) {
            'text' => $this->text((string) ($node['v'] ?? '')),
            'var' => '{{'.$this->name((string) ($node['path'] ?? ''), 'variable').'}}',
            'section' => '{{'.(! empty($node['inverted']) ? '^' : '#').($name = $this->name((string) ($node['name'] ?? ''), 'block')).'}}'
                .$this->buildNodes((array) ($node['children'] ?? []), $depth + 1).'{{/'.$name.'}}',
            'el' => $this->element($node, $depth),
            default => throw new TemplateValidationException('The document contains an unsupported element.'),
        };
    }

    private function element(array $node, int $depth): string
    {
        $tag = strtolower((string) ($node['tag'] ?? ''));
        $children = (array) ($node['children'] ?? []);
        if (! in_array($tag, self::TAGS, true)) {
            return $this->buildNodes($children, $depth + 1); // unknown tag: keep its content only
        }
        $attributes = '';
        foreach ((array) ($node['attrs'] ?? []) as $name => $value) {
            $clean = $this->attribute((string) $name, (string) $value);
            if ($clean !== null) {
                $attributes .= ' '.$name.'="'.htmlspecialchars($clean, ENT_QUOTES, 'UTF-8').'"';
            }
        }
        if (in_array($tag, self::VOID, true)) {
            return "<{$tag}{$attributes}>";
        }

        return "<{$tag}{$attributes}>".$this->buildNodes($children, $depth + 1)."</{$tag}>";
    }

    /** Typed text never becomes template syntax ("{{" is broken up), and is HTML-escaped. */
    private function text(string $value): string
    {
        return htmlspecialchars(str_replace(['{{', '}}'], ['{ {', '} }'], $value), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8', false);
    }

    private function name(string $value, string $what): string
    {
        if (! preg_match(self::NAME, $value)) {
            throw new TemplateValidationException("The document contains an invalid {$what} reference.");
        }

        return $value;
    }

    // ------------------------------------------------------------------ HTML sanitizer

    /**
     * Rebuilds any template HTML from the allowlist. Template tokens are carried through the
     * HTML parser as comments, so a {{#items}} between table rows stays exactly where it is.
     */
    public function sanitize(string $html): string
    {
        $tokens = [];
        $protected = preg_replace_callback('/\{\{(#|\^|\/)?\s*([a-zA-Z0-9_.]+)\s*\}\}/', function ($m) use (&$tokens) {
            $tokens[] = '{{'.$m[1].$m[2].'}}';

            return '<!--tok:'.(count($tokens) - 1).'-->';
        }, $html);

        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="utf-8"?><div id="__root">'.$protected.'</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $root = $document->getElementById('__root');
        if (! $root) {
            return '';
        }

        return $this->sanitizeChildren($root, $tokens);
    }

    private function sanitizeChildren(DOMNode $parent, array $tokens): string
    {
        $html = '';
        foreach ($parent->childNodes as $child) {
            $html .= $this->sanitizeNode($child, $tokens);
        }

        return $html;
    }

    private function sanitizeNode(DOMNode $node, array $tokens): string
    {
        if ($node->nodeType === XML_TEXT_NODE || $node->nodeType === XML_CDATA_SECTION_NODE) {
            return htmlspecialchars($node->nodeValue ?? '', ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8', false);
        }
        if ($node->nodeType === XML_COMMENT_NODE) {
            return preg_match('/^tok:(\d+)$/', (string) $node->nodeValue, $m) && isset($tokens[(int) $m[1]]) ? $tokens[(int) $m[1]] : '';
        }
        if (! $node instanceof DOMElement) {
            return '';
        }
        $tag = strtolower($node->tagName);
        if (in_array($tag, ['script', 'style', 'iframe', 'object', 'embed', 'template', 'noscript', 'svg', 'math', 'head', 'title', 'meta', 'link'], true)) {
            return ''; // dropped with its content
        }
        if (! in_array($tag, self::TAGS, true)) {
            return $this->sanitizeChildren($node, $tokens); // unknown wrapper: keep the content
        }
        $attributes = '';
        foreach ($node->attributes as $attribute) {
            $name = strtolower($attribute->name);
            $clean = $this->attribute($name, $attribute->value);
            if ($clean !== null) {
                $attributes .= ' '.$name.'="'.htmlspecialchars($clean, ENT_QUOTES, 'UTF-8').'"';
            }
        }
        if (in_array($tag, self::VOID, true)) {
            return "<{$tag}{$attributes}>";
        }

        return "<{$tag}{$attributes}>".$this->sanitizeChildren($node, $tokens)."</{$tag}>";
    }

    private function attribute(string $name, string $value): ?string
    {
        if (! in_array($name, self::ATTRIBUTES, true)) {
            return null;
        }
        if ($name === 'style') {
            $style = $this->style($value);

            return $style === '' ? null : $style;
        }

        return preg_match('/^[a-zA-Z0-9 %.#-]{0,20}$/', $value) ? $value : null;
    }

    /** Only allowlisted CSS properties with plain values (no url(), expression(), escapes or markup). */
    private function style(string $value): string
    {
        $declarations = [];
        foreach (explode(';', $value) as $declaration) {
            if (! str_contains($declaration, ':')) {
                continue;
            }
            [$property, $propertyValue] = array_map('trim', explode(':', $declaration, 2));
            $property = strtolower($property);
            if (! in_array($property, self::CSS_PROPERTIES, true) || $propertyValue === '') {
                continue;
            }
            if (preg_match('/url\s*\(|expression|javascript:|@import|[\\\\<>"`{}]/i', $propertyValue)) {
                continue;
            }
            $declarations[] = "{$property}:{$propertyValue}";
        }

        return implode(';', $declarations);
    }
}
