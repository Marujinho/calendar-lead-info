<?php

declare(strict_types=1);

namespace App\Console\Commands\Tenant;

use App\Console\Commands\Concerns\ReadsStringInput;
use App\Models\Tenant;
use App\Support\JobLog;
use DateTimeZone;
use Illuminate\Console\Command;
use JsonException;
use Symfony\Component\Console\Input\StreamableInputInterface;

/**
 * `tenant:create` — onboards a customer company (PRD FR-15, §12 A02/A09).
 *
 * Invariants:
 *  - Provider credentials never travel through argv: they are read from STDIN
 *    (`--credentials-stdin`) or a hidden prompt, and there is deliberately no
 *    option that takes them as a value.
 *  - Credentials are never printed, logged or echoed back in an error message;
 *    they are stored only through the model's `encrypted:array` cast.
 *  - Nothing is written unless the name, domains, timezone and credentials
 *    are all valid.
 */
final class CreateTenantCommand extends Command
{
    use ReadsStringInput;

    /** Upper bound for the credentials document read from STDIN. */
    private const MAX_CREDENTIALS_BYTES = 65536;

    protected $signature = 'tenant:create
        {name : Company name}
        {--domains=* : Internal email domain(s); repeat the option or comma-separate}
        {--timezone= : Default IANA timezone for the tenant\'s reps, e.g. America/Sao_Paulo}
        {--credentials-stdin : Read provider credentials as a JSON object from STDIN instead of prompting}';

    protected $description = 'Create a tenant (credentials via hidden prompt or STDIN, never as an argument)';

    public function handle(JobLog $log): int
    {
        $name = trim($this->stringArgument('name'));
        if ($name === '' || mb_strlen($name) > 255) {
            $this->error('Name must be 1 to 255 characters.');

            return self::FAILURE;
        }

        $domains = $this->normaliseDomains();
        if ($domains === null) {
            $this->error('Each --domains value must be a hostname such as acme.com.');

            return self::FAILURE;
        }

        if ($domains === []) {
            $this->error('At least one internal domain is required (--domains=acme.com).');

            return self::FAILURE;
        }

        $timezone = $this->stringOption('timezone');
        if ($timezone === null || ! in_array($timezone, DateTimeZone::listIdentifiers(), true)) {
            $this->error('--timezone must be an IANA identifier such as America/Sao_Paulo.');

            return self::FAILURE;
        }

        $raw = match (true) {
            $this->option('credentials-stdin') === true => $this->readCredentialsFromStdin(),
            $this->input->isInteractive() => $this->secret('Provider credentials as a JSON object (leave empty for none)'),
            default => '',
        };

        $credentials = $this->parseCredentials(is_string($raw) ? $raw : '');
        if ($credentials === false) {
            $this->error('Credentials must be a JSON object whose values are strings.');

            return self::FAILURE;
        }

        $tenant = Tenant::query()->create([
            'name' => $name,
            'internal_domains' => $domains,
            'default_timezone' => $timezone,
            'provider_credentials' => $credentials,
        ]);

        $log->info('tenant.created', ['tenant_id' => $tenant->id]);
        $this->info(sprintf('Tenant created: id %d.', $tenant->id));
        $this->line($credentials === null ? 'No provider credentials stored.' : 'Provider credentials stored encrypted.');

        return self::SUCCESS;
    }

    /**
     * Lower-cased, de-duplicated domains in input order; null when any value
     * is not a dotted hostname.
     *
     * @return list<string>|null
     */
    private function normaliseDomains(): ?array
    {
        $values = $this->option('domains');
        $domains = [];

        foreach (is_array($values) ? $values : [] as $value) {
            foreach (explode(',', is_string($value) ? $value : '') as $domain) {
                $domain = mb_strtolower(trim($domain));
                if ($domain === '') {
                    continue;
                }

                if (! str_contains($domain, '.') || filter_var($domain, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false) {
                    return null;
                }

                $domains[$domain] = true;
            }
        }

        return array_keys($domains);
    }

    /**
     * Reads the whole credentials document from the input stream (tests and
     * embedders may supply one) or the process STDIN.
     */
    private function readCredentialsFromStdin(): string
    {
        $stream = $this->input instanceof StreamableInputInterface ? $this->input->getStream() : null;
        $stream ??= fopen('php://stdin', 'r');

        if ($stream === false) {
            return '';
        }

        $contents = stream_get_contents($stream, self::MAX_CREDENTIALS_BYTES + 1);

        return is_string($contents) ? $contents : '';
    }

    /**
     * Null for "no credentials" (empty input or `{}`), the decoded map when it
     * is a JSON object of string values, false otherwise. Never includes the
     * input in any message.
     *
     * @return array<string, string>|false|null
     */
    private function parseCredentials(string $raw): array|false|null
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }

        if (strlen($raw) > self::MAX_CREDENTIALS_BYTES || ! str_starts_with($raw, '{')) {
            return false;
        }

        try {
            $decoded = json_decode($raw, true, 4, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return false;
        }

        if (! is_array($decoded)) {
            return false;
        }

        $credentials = [];
        foreach ($decoded as $key => $value) {
            if (! is_string($key) || ! is_string($value)) {
                return false;
            }

            $credentials[$key] = $value;
        }

        return $credentials === [] ? null : $credentials;
    }
}
