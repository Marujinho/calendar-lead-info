<?php

declare(strict_types=1);

namespace App\Services\Digest;

/**
 * Decides whether a provider-supplied LinkedIn URL may be rendered as a link
 * in the digest email (PRD A05).
 *
 * Invariant: a non-null result is a syntactically valid `https` URL whose host
 * is `linkedin.com` or a subdomain of it, with no userinfo part. Everything
 * else (other schemes, look-alike hosts, `user@host` tricks) yields null, and
 * the email shows "—" instead of a link.
 */
final class LinkedInUrl
{
    private const ALLOWED_HOST = 'linkedin.com';

    public static function safe(?string $url): ?string
    {
        if ($url === null || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return null;
        }

        $parts = parse_url($url);

        if ($parts === false || isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }

        $scheme = strtolower($parts['scheme'] ?? '');
        $host = strtolower($parts['host'] ?? '');

        if ($scheme !== 'https') {
            return null;
        }

        if ($host !== self::ALLOWED_HOST && ! str_ends_with($host, '.'.self::ALLOWED_HOST)) {
            return null;
        }

        return $url;
    }
}
