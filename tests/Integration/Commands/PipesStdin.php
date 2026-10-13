<?php

declare(strict_types=1);

namespace Tests\Integration\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Runs a command the way a shell pipe would (`echo ... | php artisan x`):
 * non-interactive, with the given bytes on its input stream. Artisan's
 * PendingCommand cannot supply STDIN, so this builds the input directly.
 */
trait PipesStdin
{
    /**
     * @param  class-string<Command>  $commandClass
     * @param  array<string, mixed>  $parameters
     * @return array{int, string}
     */
    protected function runWithStdin(string $commandClass, array $parameters, string $stdin): array
    {
        $stream = fopen('php://memory', 'r+');
        self::assertIsResource($stream);
        fwrite($stream, $stdin);
        rewind($stream);

        $input = new ArrayInput($parameters);
        $input->setStream($stream);
        $input->setInteractive(false);
        $output = new BufferedOutput;

        $command = $this->app->make($commandClass);
        $command->setLaravel($this->app);
        $code = $command->run($input, $output);

        return [$code, $output->fetch()];
    }
}
