<?php

declare(strict_types=1);

namespace Modules\Tournaments\Listeners\Concerns;

use App\Notifications\Support\EscapesMarkdown;
use Modules\Tournaments\Domain\Entities\Participant;
use Modules\Tournaments\Domain\Enums\MatchResult;

/**
 * Names and results for the match mails, escaped for Markdown where a person chose them.
 * Needs a UserDataProviderInterface in $this->users.
 */
trait DescribesMatches
{
    private function nameOf(?Participant $participant): string
    {
        $name = $participant?->userId() !== null
            ? $this->users->getUserInfo($participant->userId())['name'] ?? null
            : $participant?->guestName();

        return EscapesMarkdown::escape($name ?? (string) __('tournaments::messages.participants.unknown'));
    }

    /**
     * "Gana Ana (20-15)" or "Empate (15-15)", with escaped names.
     */
    private function resultSummary(MatchResult $result, ?int $player1Score, ?int $player2Score, string $player1Name, string $player2Name): string
    {
        $summary = match ($result) {
            MatchResult::PlayerOneWin => __('tournaments::messages.emails.match_result.win', ['name' => $player1Name]),
            MatchResult::PlayerTwoWin => __('tournaments::messages.emails.match_result.win', ['name' => $player2Name]),
            default => $result->label(),
        };

        return $player1Score !== null && $player2Score !== null
            ? "{$summary} ({$player1Score}-{$player2Score})"
            : $summary;
    }
}
