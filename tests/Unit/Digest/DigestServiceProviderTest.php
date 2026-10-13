<?php

declare(strict_types=1);

namespace Tests\Unit\Digest;

use App\Contracts\DigestBuilder;
use App\Contracts\DigestMailer;
use App\Services\Digest\EloquentDigestBuilder;
use App\Services\Digest\MailableDigestMailer;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class DigestServiceProviderTest extends TestCase
{
    #[Test]
    public function it_binds_the_digest_contracts_to_their_implementations(): void
    {
        self::assertInstanceOf(EloquentDigestBuilder::class, $this->app->make(DigestBuilder::class));
        self::assertInstanceOf(MailableDigestMailer::class, $this->app->make(DigestMailer::class));
    }
}
