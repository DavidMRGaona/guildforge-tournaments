<?php

declare(strict_types=1);

namespace Modules\Tournaments\Domain\Enums;

enum MatchResult: string
{
    case PlayerOneWin = 'player_one_win';
    case PlayerTwoWin = 'player_two_win';
    case Draw = 'draw';
    case DoubleLoss = 'double_loss';
    case Bye = 'bye';
    case NotPlayed = 'not_played';

    /**
     * Get human-readable label for the result.
     */
    public function label(): string
    {
        return match ($this) {
            self::PlayerOneWin => __('tournaments::messages.match_result.player_one_win'),
            self::PlayerTwoWin => __('tournaments::messages.match_result.player_two_win'),
            self::Draw => __('tournaments::messages.match_result.draw'),
            self::DoubleLoss => __('tournaments::messages.match_result.double_loss'),
            self::Bye => __('tournaments::messages.match_result.bye'),
            self::NotPlayed => __('tournaments::messages.match_result.not_played'),
        };
    }

    /**
     * Get badge color for Filament UI.
     */
    public function color(): string
    {
        return match ($this) {
            self::PlayerOneWin, self::PlayerTwoWin => 'success',
            self::Draw => 'warning',
            self::DoubleLoss => 'danger',
            self::Bye => 'info',
            self::NotPlayed => 'gray',
        };
    }

    /**
     * Get points for player 1 (used for win percentage calculation).
     */
    public function player1Points(): float
    {
        return match ($this) {
            self::PlayerOneWin, self::Bye => 1.0,
            self::Draw => 0.5,
            self::PlayerTwoWin, self::DoubleLoss, self::NotPlayed => 0.0,
        };
    }

    /**
     * Get points for player 2 (used for win percentage calculation).
     */
    public function player2Points(): float
    {
        return match ($this) {
            self::PlayerTwoWin => 1.0,
            self::Draw => 0.5,
            self::PlayerOneWin, self::DoubleLoss, self::Bye, self::NotPlayed => 0.0,
        };
    }

    /**
     * Check if the match has been played/completed.
     */
    public function isCompleted(): bool
    {
        return $this !== self::NotPlayed;
    }

    /**
     * Check if this is a bye result.
     */
    public function isBye(): bool
    {
        return $this === self::Bye;
    }

    /**
     * Get all result values.
     *
     * @return array<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Get options for form select fields (excluding not_played).
     *
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];
        foreach (self::cases() as $case) {
            if ($case !== self::NotPlayed) {
                $options[$case->value] = $case->label();
            }
        }

        return $options;
    }
}
