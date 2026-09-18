<?php

namespace SafferIt\LibrenmsNetconf\Extract;

use DOMNode;

/**
 * Text templates for descriptions and indexes: {index}, {re}, {n}, {row:<xpath>} and
 * {device:<fact>} (hostname etc., supplied by the caller). {re:system} falls back to
 * "system" when the row is not inside a multi-routing-engine wrapper.
 */
final class Template
{
    /**
     * {n} alone does not make a template: "device-name[{n}]" stays an XPath with the repeat
     * counter substituted.
     */
    public static function isTemplate(string $text): bool
    {
        return str_contains($text, '{') && preg_match('/\{(index|re|row:|device:)/', $text) === 1;
    }

    /**
     * @param  array<string, string>  $vars  index, re, n, device.*
     */
    public static function render(string $template, XmlDocument $doc, DOMNode $row, array $vars): string
    {
        $out = preg_replace_callback('/\{row:((?:[^{}]|\{[^{}]*\})+)\}/', function (array $m) use ($doc, $row, $vars) {
            $expr = self::substituteN($m[1], $vars['n'] ?? null);
            $value = $doc->scalar($expr, $row);

            return self::stringify($value);
        }, $template) ?? $template;

        // {re} / {index} / {n}, optionally with a fallback for empty values: {re:system}
        $out = preg_replace_callback('/\{(index|re|n)(?::([^{}]*))?\}/', function (array $m) use ($vars) {
            $value = $vars[$m[1]] ?? '';

            return $value !== '' ? $value : ($m[2] ?? '');
        }, $out) ?? $out;
        $out = preg_replace_callback('/\{device:([a-z_]+)\}/i', fn (array $m) => $vars['device.' . strtolower($m[1])] ?? '', $out) ?? $out;

        return trim(preg_replace('/\s+/', ' ', $out) ?? $out);
    }

    /** Replace the {n} placeholder used by repeat: mappings inside an XPath. */
    public static function substituteN(string $expression, ?string $n): string
    {
        return $n === null ? $expression : str_replace('{n}', $n, $expression);
    }

    public static function stringify(string|float|bool|null $value): string
    {
        if ($value === null) {
            return '';
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        if (is_float($value)) {
            return $value == (int) $value && abs($value) < 1e15 ? (string) (int) $value : (string) $value;
        }

        return $value;
    }
}
