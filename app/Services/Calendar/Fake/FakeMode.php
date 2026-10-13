<?php

declare(strict_types=1);

namespace App\Services\Calendar\Fake;

use InvalidArgumentException;

/**
 * Behaviour of the FakeCalendarProvider, from `config('calendar.fake.mode')`.
 * Lets local runs and tests reproduce every provider failure class (PRD A10).
 */
enum FakeMode: string
{
    case Ok = 'ok';
    case Fail = 'fail';
    case Timeout = 'timeout';
    case Malformed = 'malformed';

    public static function fromConfig(string $value): self
    {
        return self::tryFrom($value) ?? throw new InvalidArgumentException(sprintf(
            'Unknown calendar.fake.mode "%s"; expected one of: %s',
            $value,
            implode(', ', array_map(static fn (self $m): string => $m->value, self::cases())),
        ));
    }
}
