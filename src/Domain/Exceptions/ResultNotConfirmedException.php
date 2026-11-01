<?php

declare(strict_types=1);

namespace Modules\Tournaments\Domain\Exceptions;

use DomainException;

final class ResultNotConfirmedException extends DomainException
{
    private function __construct(string $message, private readonly bool $changed = false)
    {
        parent::__construct($message);
    }

    public static function noPendingReport(string $matchId): self
    {
        return new self("Match {$matchId} has no reported result waiting for confirmation.");
    }

    public static function alreadyDisputed(string $matchId): self
    {
        return new self("The result reported for match {$matchId} is already disputed.");
    }

    public static function reportChanged(string $matchId): self
    {
        return new self("The result reported for match {$matchId} changed since it was shown.", true);
    }

    /**
     * The report was corrected after it was shown: show it again rather than say nothing waits.
     */
    public function reportWasChanged(): bool
    {
        return $this->changed;
    }

    public static function disputed(string $matchId): self
    {
        return new self("Match {$matchId} result has been disputed and requires admin intervention.");
    }
}
