<?php

declare(strict_types=1);

namespace Modules\Tournaments\Infrastructure\Observers;

use App\Domain\Surveys\Events\SurveySubjectCancelled;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\TournamentModel;
use Modules\Tournaments\Infrastructure\Surveys\TournamentSurveySubjectSource;

/**
 * A tournament deleted before it ended will never end: a surveys module, if any, drops the
 * surveys prepared for its end (core event).
 */
final readonly class TournamentObserver implements ShouldHandleEventsAfterCommit
{
    public function deleted(TournamentModel $tournament): void
    {
        if ($tournament->status->isFinal()) {
            return;
        }

        event(new SurveySubjectCancelled(TournamentSurveySubjectSource::TYPE, (string) $tournament->getKey(), now()->toDateTimeImmutable()));
    }
}
