<?php

declare(strict_types=1);

namespace Tests\Unit\Digest;

use App\Mail\MarkdownCell;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class MarkdownCellTest extends TestCase
{
    #[Test]
    public function missing_or_blank_values_become_a_dash(): void
    {
        self::assertSame('—', MarkdownCell::of(null, true));
        self::assertSame('—', MarkdownCell::of('', false));
        self::assertSame('—', MarkdownCell::of(" \r\n\t ", true));
    }

    #[Test]
    public function line_breaks_and_control_characters_collapse_to_one_space(): void
    {
        self::assertSame('Eve Bcc: x', MarkdownCell::of("Eve\r\n\r\nBcc:\tx", false));
        self::assertSame('a b', MarkdownCell::of("a\u{0000}\u{0007}b", true));
        self::assertSame('Ana Silva', MarkdownCell::of('  Ana   Silva  ', true));
    }

    #[Test]
    public function pipes_are_entity_encoded_for_html_only(): void
    {
        self::assertSame('A &#124; B', MarkdownCell::of('A | B', true));
        self::assertSame('A | B', MarkdownCell::of('A | B', false));
    }

    #[Test]
    public function other_characters_are_left_for_blade_to_escape(): void
    {
        self::assertSame('<script>[x](y) & Co', MarkdownCell::of('<script>[x](y) & Co', true));
    }
}
