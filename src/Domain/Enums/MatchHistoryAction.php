<?php

declare(strict_types=1);

namespace Modules\Tournaments\Domain\Enums;

/**
 * How the official result of a match changed.
 */
enum MatchHistoryAction: string
{
    // Entered by the organization
    case Recorded = 'recorded';
    // Reported by a player in a tournament that trusts the players
    case Reported = 'reported';
    // A player's report accepted by the opponent or the organization
    case Confirmed = 'confirmed';
    // Cleared by the organization
    case Reset = 'reset';

    public function label(): string
    {
        return match ($this) {
            self::Recorded => __('tournaments::messages.match_history_action.recorded'),
            self::Reported => __('tournaments::messages.match_history_action.reported'),
            self::Confirmed => __('tournaments::messages.match_history_action.confirmed'),
            self::Reset => __('tournaments::messages.match_history_action.reset'),
        };
    }
}
