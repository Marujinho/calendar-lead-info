<?php

declare(strict_types=1);

namespace Tests\Unit\Digest;

use App\Services\Digest\LinkedInUrl;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class LinkedInUrlTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function safeUrls(): array
    {
        return [
            'bare host' => ['https://linkedin.com/in/ana'],
            'www subdomain' => ['https://www.linkedin.com/in/ana-silva'],
            'country subdomain' => ['https://pt.linkedin.com/in/ana'],
            'upper-case scheme and host' => ['HTTPS://WWW.LinkedIn.COM/in/ana'],
            'query string' => ['https://www.linkedin.com/in/ana?trk=profile'],
        ];
    }

    /**
     * @return array<string, array{string|null}>
     */
    public static function unsafeUrls(): array
    {
        return [
            'null' => [null],
            'empty' => [''],
            'not a url' => ['linkedin.com/in/ana'],
            'javascript scheme' => ['javascript:alert(1)'],
            'javascript scheme with slashes' => ['javascript://linkedin.com/%0Aalert(1)'],
            'plain http' => ['http://www.linkedin.com/in/ana'],
            'other scheme' => ['ftp://linkedin.com/in/ana'],
            'other host with linkedin in the path' => ['https://evil.com/linkedin.com'],
            'other host' => ['https://evil.com'],
            'linkedin as a subdomain of another host' => ['https://linkedin.com.evil.com/in/ana'],
            'look-alike suffix' => ['https://evil-linkedin.com/in/ana'],
            'userinfo pointing elsewhere' => ['https://linkedin.com@evil.com/in/ana'],
            'userinfo on linkedin host' => ['https://user:pass@www.linkedin.com/in/ana'],
            'protocol-relative' => ['//www.linkedin.com/in/ana'],
            'whitespace inside' => ['https://www.linkedin.com/in/ana silva'],
            'data uri' => ['data:text/html,<script>alert(1)</script>'],
        ];
    }

    #[Test]
    #[DataProvider('safeUrls')]
    public function it_returns_https_linkedin_urls_unchanged(string $url): void
    {
        self::assertSame($url, LinkedInUrl::safe($url));
    }

    #[Test]
    #[DataProvider('unsafeUrls')]
    public function it_rejects_anything_else(?string $url): void
    {
        self::assertNull(LinkedInUrl::safe($url));
    }
}
