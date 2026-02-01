<?php

declare(strict_types=1);

namespace Modules\Tournaments\Domain\Exceptions;

use DomainException;

final class GameProfileNotFoundException extends DomainException
{
    private function __construct(string $message)
    {
        parent::__construct($message);
    }

    public static function withId(string $id): self
    {
        return new self("Game profile with ID {$id} not found.");
    }

    public static function withSlug(string $slug): self
    {
        return new self("Game profile with slug {$slug} not found.");
    }
}
