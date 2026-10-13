<?php

declare(strict_types=1);

namespace App\Console\Commands\Rep;

use App\Console\Commands\Concerns\ReadsStringInput;
use App\Console\Commands\Concerns\ValidatesRepInput;
use App\Models\Tenant;
use App\Support\JobLog;
use App\Tenancy\TenantContext;
use Illuminate\Console\Command;
use SplFileObject;

/**
 * `rep:import` — bulk-registers reps from a CSV file (PRD FR-15).
 *
 * Invariants:
 *  - The header must be exactly `email,name,timezone` (or `email,name`);
 *    anything else aborts before a single row is written.
 *  - Each row is validated like `rep:add` and committed in its own
 *    transaction, so a bad or late row never undoes earlier reps.
 *  - Existing reps (same email, case-insensitive) are skipped, not updated.
 *  - Errors name the line number and the rule, never the row's content.
 *  - Exit code is FAILURE when any row failed, after processing every row.
 */
final class ImportRepsCommand extends Command
{
    use ReadsStringInput;
    use ValidatesRepInput;

    private const HEADERS = [['email', 'name', 'timezone'], ['email', 'name']];

    protected $signature = 'rep:import
        {tenant : Tenant id}
        {path : Path to a CSV file with header email,name,timezone (timezone optional)}';

    protected $description = 'Import sales reps for a tenant from a CSV file';

    public function handle(TenantContext $context, JobLog $log): int
    {
        $tenant = $this->findTenant($this->stringArgument('tenant'));
        if ($tenant === null) {
            $this->error('Unknown tenant.');

            return self::FAILURE;
        }

        $path = $this->stringArgument('path');
        if (! is_file($path) || ! is_readable($path)) {
            $this->error('CSV file not found or not readable.');

            return self::FAILURE;
        }

        $file = new SplFileObject($path, 'r');
        $file->setFlags(SplFileObject::READ_CSV | SplFileObject::SKIP_EMPTY | SplFileObject::READ_AHEAD | SplFileObject::DROP_NEW_LINE);
        $file->setCsvControl(',', '"', '');

        $header = null;
        $created = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($file as $index => $row) {
            if (! is_array($row) || $row === [null]) {
                continue;
            }

            /** @var list<string> $row */
            $line = $index + 1;

            if ($header === null) {
                $header = $this->normaliseHeader($row);
                if (! in_array($header, self::HEADERS, true)) {
                    $this->error('Invalid CSV header: expected "email,name,timezone" (timezone column optional).');

                    return self::FAILURE;
                }

                continue;
            }

            if (count($row) !== count($header)) {
                $this->error(sprintf('Line %d: expected %d columns, got %d.', $line, count($header), count($row)));
                $failed++;

                continue;
            }

            $email = $row[0];
            $name = $row[1];
            $timezone = trim($row[2] ?? '');
            $timezone = $timezone === '' ? $tenant->default_timezone : $timezone;

            $error = $this->repInputError($email, $name, $timezone);
            if ($error !== null) {
                $this->error(sprintf('Line %d: %s', $line, $error));
                $failed++;

                continue;
            }

            $rep = $context->run($tenant, fn (Tenant $t) => $this->createRep($log, $email, $name, $timezone));

            if ($rep === null) {
                $this->line(sprintf('Line %d: rep already exists, skipped.', $line));
                $skipped++;

                continue;
            }

            $created++;
        }

        if ($header === null) {
            $this->error('Invalid CSV header: the file is empty.');

            return self::FAILURE;
        }

        $this->info(sprintf('Import finished: %d created, %d skipped, %d failed.', $created, $skipped, $failed));

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param  list<string>  $row
     * @return list<string>
     */
    private function normaliseHeader(array $row): array
    {
        if (isset($row[0])) {
            $row[0] = preg_replace('/^\xEF\xBB\xBF/', '', $row[0]) ?? $row[0];
        }

        return array_map(static fn (string $column): string => mb_strtolower(trim($column)), $row);
    }
}
