<?php

declare(strict_types=1);

namespace Tests\Unit\Commands;

use App\Console\Commands\Concerns\ValidatesRepInput;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ValidatesRepInputTest extends TestCase
{
    private object $subject;

    protected function setUp(): void
    {
        parent::setUp();

        $this->subject = new class
        {
            use ValidatesRepInput {
                normaliseEmail as public;
                isKnownTimezone as public;
                repInputError as public;
            }
        };
    }

    #[Test]
    public function it_trims_and_lower_cases_a_valid_email(): void
    {
        self::assertSame('ana@acme.test', $this->subject->normaliseEmail('  Ana@ACME.test '));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidEmails(): iterable
    {
        yield 'empty' => [''];
        yield 'blank' => ['   '];
        yield 'no at' => ['ana.acme.test'];
        yield 'no domain' => ['ana@'];
        yield 'spaces inside' => ['an a@acme.test'];
        yield 'too long' => [str_repeat('a', 250).'@acme.test'];
    }

    #[Test]
    #[DataProvider('invalidEmails')]
    public function it_rejects_invalid_emails(string $email): void
    {
        self::assertNull($this->subject->normaliseEmail($email));
    }

    #[Test]
    public function it_knows_iana_timezones_only(): void
    {
        self::assertTrue($this->subject->isKnownTimezone('Europe/Lisbon'));
        self::assertTrue($this->subject->isKnownTimezone('UTC'));
        self::assertFalse($this->subject->isKnownTimezone('europe/lisbon'));
        self::assertFalse($this->subject->isKnownTimezone('Mars/Olympus'));
        self::assertFalse($this->subject->isKnownTimezone(''));
    }

    #[Test]
    public function a_valid_row_has_no_error(): void
    {
        self::assertNull($this->subject->repInputError('ana@acme.test', 'Ana', 'America/Sao_Paulo'));
    }

    #[Test]
    public function each_rule_has_its_own_message_without_echoing_the_input(): void
    {
        self::assertSame('Invalid email address.', $this->subject->repInputError('nope', 'Ana', 'UTC'));
        self::assertSame('Name must be 1 to 255 characters.', $this->subject->repInputError('ana@acme.test', '  ', 'UTC'));
        self::assertSame('Name must be 1 to 255 characters.', $this->subject->repInputError('ana@acme.test', str_repeat('n', 256), 'UTC'));
        self::assertNull($this->subject->repInputError('ana@acme.test', str_repeat('n', 255), 'UTC'));

        $error = $this->subject->repInputError('ana@acme.test', 'Ana', 'Mars/Olympus');
        self::assertSame('Unknown timezone; use an IANA identifier such as Europe/Lisbon.', $error);
    }
}
