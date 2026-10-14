<?php

declare(strict_types=1);

namespace Modules\Tournaments\Filament;

use DomainException;
use Modules\Tournaments\Domain\Exceptions\InvalidMatchResultException;

/**
 * Notice the panel shows when a match result is rejected.
 */
final class InvalidMatchResultMessage
{
    public static function for(DomainException $exception): string
    {
        return $exception instanceof InvalidMatchResultException
            && $exception->reason() === InvalidMatchResultException::SCORES_CONTRADICT_RESULT
            ? __('tournaments::messages.errors.scores_contradict_result')
            : __('tournaments::messages.errors.invalid_match_result');
    }
}
