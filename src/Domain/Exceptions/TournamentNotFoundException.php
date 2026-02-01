<?php

declare(strict_types=1);

namespace Modules\Tournaments\Domain\Exceptions;

use DomainException;

final class TournamentNotFoundException extends DomainException
{
    private function __construct(string $message)
    {
        parent::__construct($message);
    }

    public static function withId(string $id): self
    {
        return new self("Tournament with ID {$id} not found.");
    }

    public static function withSlug(string $slug): self
    {
        return new self("Tournament with slug {$slug} not found.");
    }

    public static function forEvent(string $eventId): self
    {
        return new self("No tournament found for event {$eventId}.");
    }
}
