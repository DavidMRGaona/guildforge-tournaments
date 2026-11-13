<?php

declare(strict_types=1);

namespace Modules\Tournaments\Notifications\Concerns;

use App\Application\Mail\DTOs\MailSubjectDTO;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use Modules\Tournaments\Application\DTOs\Response\TournamentResponseDTO;
use Modules\Tournaments\Application\Services\TournamentQueryServiceInterface;
use Modules\Tournaments\Domain\Repositories\ParticipantRepositoryInterface;

/**
 * What every participant notification shares to also show a notice in the bell: the tournament it
 * is about (the mail subject people can mute, and the thing a deleted tournament makes the core
 * forget) and the rule for delivering it: only to an account that is still a participant of a
 * tournament that is still public. The using class declares `$tournamentId` (null for a
 * notification queued before it existed, which then shows nothing on the site) and `$tournamentName`.
 */
trait NoticesTournamentOnTheSite
{
    public const string SUBJECT_TYPE = 'tournaments.tournament';

    public function mailSubject(): ?MailSubjectDTO
    {
        // ?? like isset(): a notification queued before this property existed comes back without it
        $tournamentId = $this->tournamentId ?? null;

        return $tournamentId === null
            ? null
            : new MailSubjectDTO(self::SUBJECT_TYPE, $tournamentId, $this->tournamentName, 'tournaments::mail.subjects.tournament');
    }

    /**
     * The tournament, read when the notice is delivered, or null when the person must not get it:
     * it is not an account, the tournament is gone or a draft again, or they are no longer in it.
     */
    private function visibleTournamentFor(object $notifiable): ?TournamentResponseDTO
    {
        $tournamentId = $this->tournamentId ?? null;

        if ($tournamentId === null || ! $notifiable instanceof UserModel) {
            return null;
        }

        $tournament = app(TournamentQueryServiceInterface::class)->find($tournamentId);

        if (! $tournament instanceof TournamentResponseDTO || $tournament->isDraft()) {
            return null;
        }

        return app(ParticipantRepositoryInterface::class)->findByUserAndTournament((string) $notifiable->getKey(), $tournamentId) === null
            ? null
            : $tournament;
    }

    /**
     * Relative, so the bell never carries an address or a token.
     */
    private function tournamentPath(TournamentResponseDTO $tournament): string
    {
        return route('tournaments.show', $tournament->slug, absolute: false);
    }
}
