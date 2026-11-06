<?php

declare(strict_types=1);

namespace Modules\Tournaments\Infrastructure\Search;

use App\Application\Search\Services\SearchIndexerInterface;
use App\Infrastructure\Persistence\Eloquent\Models\EventModel;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\GameProfileModel;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\TournamentModel;

/**
 * Keeps the search documents of tournaments in line with their rows: saving refreshes the document (the
 * source drops it when the tournament is no longer listed), deleting forgets it. The event and the game
 * profile feed the document too, so changing what it shows of them refreshes their tournaments. Deleting
 * an event deletes its tournament as a model first (TournamentsServiceProvider), which forgets it. The
 * indexer queues the work for after the commit and never throws.
 */
final class RefreshesSearchDocuments
{
    /** Event columns the document shows: its dates, title, place and image */
    private const array EVENT_COLUMNS = ['title', 'start_date', 'end_date', 'location', 'image_public_id'];

    public static function register(): void
    {
        TournamentModel::saved(static function (TournamentModel $tournament): void {
            self::indexer()->refresh(TournamentSearchSource::TYPE, $tournament->id);
        });
        TournamentModel::deleted(static function (TournamentModel $tournament): void {
            self::indexer()->forget(TournamentSearchSource::TYPE, $tournament->id);
        });

        EventModel::saved(static function (EventModel $event): void {
            if ($event->wasChanged(self::EVENT_COLUMNS)) {
                self::refreshListed(TournamentModel::query()->publiclyListed()->where('event_id', $event->getKey())->pluck('id')->all());
            }
        });

        GameProfileModel::saved(static function (GameProfileModel $profile): void {
            if ($profile->wasChanged('name')) {
                self::refreshListed(TournamentModel::query()->publiclyListed()->where('game_profile_id', $profile->getKey())->pluck('id')->all());
            }
        });
    }

    /**
     * @param  array<int, mixed>  $ids
     */
    private static function refreshListed(array $ids): void
    {
        $indexer = self::indexer();

        foreach ($ids as $id) {
            $indexer->refresh(TournamentSearchSource::TYPE, (string) $id);
        }
    }

    private static function indexer(): SearchIndexerInterface
    {
        return resolve(SearchIndexerInterface::class);
    }
}
