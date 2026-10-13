<?php

declare(strict_types=1);

namespace App\Mail;

/**
 * Prepares one provider-supplied value for a line or table cell of a Markdown
 * mail (PRD FR-8, A05).
 *
 * Invariants:
 *  - The result is a single line: every run of whitespace or control
 *    characters (including CR/LF) becomes one space, so a value can never
 *    start a new Markdown block or forge a line in the plain-text part.
 *  - A missing or blank value becomes "—".
 *  - For the HTML part, `|` is written as the `&#124;` entity so it cannot
 *    split a Markdown table cell; the plain-text part keeps the literal `|`.
 *
 * HTML escaping is not done here: the view echoes the result with `{{ }}`.
 */
final class MarkdownCell
{
    public const MISSING = '—';

    public static function of(?string $value, bool $forHtml): string
    {
        $line = trim((string) preg_replace('/[\s\p{C}]+/u', ' ', $value ?? ''));

        if ($line === '') {
            return self::MISSING;
        }

        return $forHtml ? str_replace('|', '&#124;', $line) : $line;
    }
}
