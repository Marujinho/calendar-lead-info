<?php

namespace Tests\Unit\Config;

use Tests\TestCase;

class MailConfigTest extends TestCase
{
    public function test_ses_mailer_is_configured(): void
    {
        $this->assertSame('ses', config('mail.mailers.ses.transport'));
    }

    public function test_smtp_mailer_defaults_to_local_mailpit_port(): void
    {
        $this->assertSame(1025, (int) config('mail.mailers.smtp.port'));
    }
}
