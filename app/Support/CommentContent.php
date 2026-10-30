<?php

namespace App\Support;

use Dom\HTMLDocument;
use Dom\Node;
use Illuminate\Validation\ValidationException;

final class CommentContent
{
    private const TAGS = ['p', 'div', 'br', 'span', 'b', 'strong', 'i', 'em', 'u', 's', 'strike', 'del', 'ul', 'ol', 'li', 'blockquote', 'pre', 'code'];

    private const DROP = ['script', 'style', 'iframe', 'object', 'embed', 'applet', 'svg', 'math', 'template', 'head', 'title', 'noscript'];

    public static function formats(): array
    {
        static $formats;

        return $formats ??= json_decode(file_get_contents(dirname(__DIR__, 2).'/resources/comment-formats.json'), true, flags: JSON_THROW_ON_ERROR);
    }

    public static function html(?string $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }
        $document = HTMLDocument::createFromString('<!doctype html><html><head><meta charset="utf-8"></head><body>'.$value.'</body></html>', LIBXML_NOERROR, 'UTF-8');

        return self::children($document->body);
    }

    private static function children(Node $parent, int $depth = 0): string
    {
        if ($depth > 64) {
            return '';
        }
        $html = '';
        foreach ($parent->childNodes as $node) {
            if ($node->nodeType === XML_TEXT_NODE) {
                $html .= htmlspecialchars($node->textContent, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

                continue;
            }
            if ($node->nodeType !== XML_ELEMENT_NODE || in_array($node->localName, self::DROP, true)) {
                continue;
            }
            $tag = $node->localName;
            $content = self::children($node, $depth + 1);
            if (! in_array($tag, self::TAGS, true)) {
                $html .= $content;

                continue;
            }
            $style = self::style($node->getAttribute('style') ?? '', $node->getAttribute('class') ?? '');
            $html .= '<'.$tag.($style !== '' ? ' style="'.$style.'"' : '').'>'.($tag === 'br' ? '' : $content.'</'.$tag.'>');
        }

        return $html;
    }

    private static function style(string $value, string $classes): string
    {
        $formats = self::formats();
        $styles = [];
        foreach (preg_split('/\s+/', $classes) as $class) {
            if (str_starts_with($class, 'ql-font-') && isset($formats['fonts'][substr($class, 8)])) {
                $styles['font-family'] = $formats['fonts'][substr($class, 8)];
            }
        }
        foreach (explode(';', $value) as $rule) {
            $pair = explode(':', $rule, 2);
            if (count($pair) !== 2) {
                continue;
            }
            [$key, $candidate] = array_map('trim', $pair);
            $key = strtolower($key);
            if ($key === 'color') {
                $candidate = strtolower($candidate);
                if (preg_match('/^rgb\(\s*(\d{1,3})\s*,\s*(\d{1,3})\s*,\s*(\d{1,3})\s*\)$/D', $candidate, $rgb)
                    && max((int) $rgb[1], (int) $rgb[2], (int) $rgb[3]) <= 255) {
                    $candidate = sprintf('#%02x%02x%02x', $rgb[1], $rgb[2], $rgb[3]);
                }
            }
            $allowed = match ($key) {
                'color' => $formats['colors'], 'font-size' => $formats['sizes'], 'font-family' => array_values($formats['fonts']), default => [],
            };
            if (in_array($candidate, $allowed, true)) {
                $styles[$key] = $candidate;
            }
        }

        return implode('; ', array_map(fn ($key) => $key.': '.$styles[$key], array_keys($styles)));
    }

    public static function text(?string $value): string
    {
        $html = self::html($value);
        $html = preg_replace('/<\/?(?:p|div|li|ul|ol|blockquote|pre)\b[^>]*>|<br>/i', "\n", $html);
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim(preg_replace('/\n[ \t]*\n+/', "\n\n", str_replace("\u{00a0}", ' ', $text)));
    }

    public static function forStorage(string $value, string $field = 'body'): string
    {
        if (trim(preg_replace('/[\s\x{200B}-\x{200D}\x{FEFF}]+/u', '', self::text($value))) === '') {
            throw ValidationException::withMessages([$field => 'Écris un commentaire avant de l’enregistrer.']);
        }

        // Keep ordinary text unchanged; only rich input needs canonical HTML storage.
        return preg_match('/<\/?[a-z][^>]*>/i', $value) ? self::html($value) : trim($value);
    }
}
