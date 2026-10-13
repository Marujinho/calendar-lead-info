<?php

declare(strict_types=1);

namespace Tests\Integration\Commands;

use App\Console\Commands\Tenant\CreateTenantCommand;
use App\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Console\Input\InputOption;
use Tests\Support\IntegrationTestCase;

final class CreateTenantCommandTest extends IntegrationTestCase
{
    use PipesStdin;
    use RecordsJobLog;

    private const PROMPT = 'Provider credentials as a JSON object (leave empty for none)';

    private const SECRET = 's3cr3t-value-never-shown';

    protected function setUp(): void
    {
        parent::setUp();

        $this->recordJobLog();
    }

    private function onlyTenant(): Tenant
    {
        $tenants = Tenant::query()->get();
        self::assertCount(1, $tenants);

        return $tenants->firstOrFail();
    }

    #[Test]
    public function it_creates_a_tenant_with_normalised_domains_and_no_credentials(): void
    {
        $this->artisan('tenant:create', [
            'name' => '  Acme  ',
            '--domains' => [' Acme.TEST ', 'acme.test,Acme-Corp.com'],
            '--timezone' => 'America/Sao_Paulo',
        ])
            ->expectsQuestion(self::PROMPT, '')
            ->expectsOutputToContain('Tenant created: id ')
            ->expectsOutputToContain('No provider credentials stored.')
            ->assertExitCode(Command::SUCCESS);

        $tenant = $this->onlyTenant();
        self::assertSame('Acme', $tenant->name);
        self::assertSame(['acme.test', 'acme-corp.com'], $tenant->internal_domains);
        self::assertSame('America/Sao_Paulo', $tenant->default_timezone);
        self::assertTrue($tenant->credentials()->isEmpty());
        self::assertNull(DB::table('tenants')->where('id', $tenant->id)->value('provider_credentials'));
        self::assertSame(['tenant.created'], $this->loggedEvents());
        self::assertSame($tenant->id, $this->logRecords[0]['context']['tenant_id']);
    }

    #[Test]
    public function it_prints_the_new_tenant_id(): void
    {
        $this->artisan('tenant:create', ['name' => 'Acme', '--domains' => ['acme.test'], '--timezone' => 'UTC', '--no-interaction' => true])
            ->assertExitCode(Command::SUCCESS);

        $id = $this->onlyTenant()->id;

        $this->artisan('tenant:create', ['name' => 'Beta', '--domains' => ['beta.test'], '--timezone' => 'UTC', '--no-interaction' => true])
            ->expectsOutputToContain(sprintf('Tenant created: id %d.', $id + 1))
            ->assertExitCode(Command::SUCCESS);
    }

    #[Test]
    public function credentials_from_the_hidden_prompt_are_stored_encrypted_and_never_shown(): void
    {
        $this->artisan('tenant:create', ['name' => 'Acme', '--domains' => ['acme.test'], '--timezone' => 'UTC'])
            ->expectsQuestion(self::PROMPT, '{"client_secret":"'.self::SECRET.'"}')
            ->expectsOutputToContain('Provider credentials stored encrypted.')
            ->doesntExpectOutputToContain(self::SECRET)
            ->assertExitCode(Command::SUCCESS);

        $tenant = $this->onlyTenant();
        $raw = (string) DB::table('tenants')->where('id', $tenant->id)->value('provider_credentials');
        self::assertNotSame('', $raw);
        self::assertStringNotContainsString(self::SECRET, $raw);
        self::assertSame(self::SECRET, $tenant->credentials()->get('client_secret'));
        self::assertStringNotContainsString(self::SECRET, $this->loggedText());
    }

    #[Test]
    public function credentials_from_stdin_are_stored_encrypted_and_never_shown(): void
    {
        [$code, $output] = $this->runWithStdin(CreateTenantCommand::class,
            ['name' => 'Acme', '--domains' => ['acme.test'], '--timezone' => 'Europe/Lisbon', '--credentials-stdin' => true],
            '{"client_id":"id-1","client_secret":"'.self::SECRET."\"}\n",
        );

        self::assertSame(Command::SUCCESS, $code);
        self::assertStringContainsString('Provider credentials stored encrypted.', $output);
        self::assertStringNotContainsString(self::SECRET, $output);

        $tenant = $this->onlyTenant();
        $raw = (string) DB::table('tenants')->where('id', $tenant->id)->value('provider_credentials');
        self::assertStringNotContainsString(self::SECRET, $raw);
        self::assertSame(['client_id' => 'id-1', 'client_secret' => self::SECRET], $tenant->credentials()->toArray());
        self::assertStringNotContainsString(self::SECRET, $this->loggedText());
    }

    #[Test]
    public function empty_stdin_or_an_empty_object_means_no_credentials(): void
    {
        [$code] = $this->runWithStdin(CreateTenantCommand::class, ['name' => 'Acme', '--domains' => ['acme.test'], '--timezone' => 'UTC', '--credentials-stdin' => true], '');
        self::assertSame(Command::SUCCESS, $code);

        [$code] = $this->runWithStdin(CreateTenantCommand::class, ['name' => 'Beta', '--domains' => ['beta.test'], '--timezone' => 'UTC', '--credentials-stdin' => true], '{}');
        self::assertSame(Command::SUCCESS, $code);

        foreach (Tenant::query()->get() as $tenant) {
            self::assertTrue($tenant->credentials()->isEmpty());
        }
    }

    #[Test]
    public function malformed_credentials_fail_without_echoing_them_and_write_nothing(): void
    {
        foreach (['not-json '.self::SECRET, '["'.self::SECRET.'"]', '{"client_secret":{"nested":"'.self::SECRET.'"}}', '{"n":1}'] as $payload) {
            [$code, $output] = $this->runWithStdin(CreateTenantCommand::class,
                ['name' => 'Acme', '--domains' => ['acme.test'], '--timezone' => 'UTC', '--credentials-stdin' => true],
                $payload,
            );

            self::assertSame(Command::FAILURE, $code, $payload);
            self::assertStringContainsString('Credentials must be a JSON object whose values are strings.', $output);
            self::assertStringNotContainsString(self::SECRET, $output);
        }

        self::assertSame(0, Tenant::query()->count());
        self::assertSame([], $this->logRecords);
    }

    #[Test]
    public function an_unknown_timezone_fails(): void
    {
        $this->artisan('tenant:create', ['name' => 'Acme', '--domains' => ['acme.test'], '--timezone' => 'Mars/Olympus'])
            ->expectsOutputToContain('--timezone must be an IANA identifier')
            ->assertExitCode(Command::FAILURE);

        self::assertSame(0, Tenant::query()->count());
    }

    #[Test]
    public function a_missing_timezone_fails(): void
    {
        $this->artisan('tenant:create', ['name' => 'Acme', '--domains' => ['acme.test']])
            ->expectsOutputToContain('--timezone must be an IANA identifier')
            ->assertExitCode(Command::FAILURE);

        self::assertSame(0, Tenant::query()->count());
    }

    #[Test]
    public function no_domains_fails(): void
    {
        $this->artisan('tenant:create', ['name' => 'Acme', '--timezone' => 'UTC'])
            ->expectsOutputToContain('At least one internal domain is required')
            ->assertExitCode(Command::FAILURE);

        $this->artisan('tenant:create', ['name' => 'Acme', '--domains' => [' , '], '--timezone' => 'UTC'])
            ->expectsOutputToContain('At least one internal domain is required')
            ->assertExitCode(Command::FAILURE);

        self::assertSame(0, Tenant::query()->count());
    }

    #[Test]
    public function a_malformed_domain_fails(): void
    {
        foreach (['localhost', 'ana@acme.test', 'acme..test', '-acme.test', 'acme test.com'] as $domain) {
            $this->artisan('tenant:create', ['name' => 'Acme', '--domains' => ['ok.test', $domain], '--timezone' => 'UTC'])
                ->expectsOutputToContain('Each --domains value must be a hostname')
                ->assertExitCode(Command::FAILURE);
        }

        self::assertSame(0, Tenant::query()->count());
    }

    #[Test]
    public function an_empty_name_fails(): void
    {
        $this->artisan('tenant:create', ['name' => '   ', '--domains' => ['acme.test'], '--timezone' => 'UTC'])
            ->expectsOutputToContain('Name must be 1 to 255 characters.')
            ->assertExitCode(Command::FAILURE);

        self::assertSame(0, Tenant::query()->count());
    }

    #[Test]
    public function no_option_accepts_credentials_as_a_value(): void
    {
        $definition = $this->app->make(CreateTenantCommand::class)->getDefinition();

        $credentialOptions = array_filter(
            $definition->getOptions(),
            static fn (InputOption $o): bool => str_contains($o->getName(), 'credential') || str_contains($o->getName(), 'secret'),
        );

        self::assertSame(['credentials-stdin'], array_keys($credentialOptions));
        self::assertFalse($credentialOptions['credentials-stdin']->acceptValue());
    }
}
