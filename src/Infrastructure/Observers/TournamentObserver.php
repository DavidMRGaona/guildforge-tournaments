<?php

declare(strict_types=1);

namespace Modules\Tournaments\Infrastructure\Observers;

use App\Application\Mail\Services\MailSubjectMuteServiceInterface;
use App\Domain\Surveys\Events\SurveySubjectCancelled;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Support\Facades\Log;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\TournamentModel;
use Modules\Tournaments\Infrastructure\Surveys\TournamentSurveySubjectSource;
use Throwable;

/**
 * A tournament deleted before it ended will never end: a surveys module, if any, drops the
 * surveys prepared for its end (core event).
 */
final readonly class TournamentObserver implements ShouldHandleEventsAfterCommit
{
    private const string SUBJECT_TYPE = 'tournaments.tournament';

    public function deleted(TournamentModel $tournament): void
    {
        $this->forgetNotices((string) $tournament->getKey());

        if ($tournament->status->isFinal()) {
            return;
        }

        event(new SurveySubjectCancelled(TournamentSurveySubjectSource::TYPE, (string) $tournament->getKey(), now()->toDateTimeImmutable()));
    }

    /**
     * The bell must not link to a page that is gone: forget the notices about the tournament
     * (and the mutes of it) for everybody.
     */
    private function forgetNotices(string $tournamentId): void
    {
        try {
            app(MailSubjectMuteServiceInterface::class)->forgetSubject(self::SUBJECT_TYPE, $tournamentId);
        } catch (Throwable $exception) {
            Log::warning('Tournaments could not forget the mutes and notices of a deleted tournament.', [
                'tournament_id' => $tournamentId,
                'exception' => $exception->getMessage(),
            ]);
        }
    }
}
