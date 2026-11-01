<?php

declare(strict_types=1);

namespace Modules\Tournaments\Domain\Exceptions;

use DomainException;

final class InvalidMatchResultException extends DomainException
{
    public const string BYE_MISMATCH = 'bye_mismatch';

    public const string SCORES_CONTRADICT_RESULT = 'scores_contradict_result';

    public const string NOT_A_PLAYER_RESULT = 'not_a_player_result';

    private function __construct(string $message, private readonly string $reason)
    {
        parent::__construct($message);
    }

    public static function byeOnlyForByeMatches(string $matchId): self
    {
        return new self("Match {$matchId} has two players and cannot be recorded as a bye.", self::BYE_MISMATCH);
    }

    public static function byeMatchOnlyAcceptsBye(string $matchId): self
    {
        return new self("Match {$matchId} is a bye and only accepts the bye result.", self::BYE_MISMATCH);
    }

    public static function scoresContradictResult(string $matchId): self
    {
        return new self("The scores of match {$matchId} contradict its result.", self::SCORES_CONTRADICT_RESULT);
    }

    public static function notAPlayerResult(string $matchId): self
    {
        return new self("Players report a win or a draw for match {$matchId}; any other result is the organization's.", self::NOT_A_PLAYER_RESULT);
    }

    /**
     * Why the result was rejected: one of the class constants.
     */
    public function reason(): string
    {
        return $this->reason;
    }
}
