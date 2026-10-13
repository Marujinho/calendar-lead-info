<?php

declare(strict_types=1);

namespace Tests\Unit\Commands;

use App\Console\Commands\Concerns\ReadsStringInput;
use Illuminate\Console\Command;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;

final class ReadsStringInputTest extends TestCase
{
    /**
     * @param  array<string, mixed>  $parameters
     */
    private function command(array $parameters): ReadsStringInputFixture
    {
        $command = new ReadsStringInputFixture;
        $command->setInput(new ArrayInput($parameters, $command->getDefinition()));

        return $command;
    }

    #[Test]
    public function it_returns_string_arguments_and_options(): void
    {
        $command = $this->command(['value' => 'abc', '--opt' => 'Europe/Lisbon']);

        self::assertSame('abc', $command->stringArgument('value'));
        self::assertSame('Europe/Lisbon', $command->stringOption('opt'));
    }

    #[Test]
    public function a_missing_option_is_null(): void
    {
        self::assertNull($this->command(['value' => 'abc'])->stringOption('opt'));
    }

    #[Test]
    public function non_string_values_are_never_cast(): void
    {
        $command = $this->command(['value' => 'abc', 'rest' => ['x', 'y'], '--list' => ['a', 'b'], '--flag' => true]);

        self::assertSame('', $command->stringArgument('rest'));

        self::assertNull($command->stringOption('list'));
        self::assertNull($command->stringOption('flag'));
    }
}

final class ReadsStringInputFixture extends Command
{
    use ReadsStringInput {
        stringArgument as public;
        stringOption as public;
    }

    protected $signature = 'test:reads {value} {rest?*} {--opt=} {--list=*} {--flag}';
}
