<?php

declare(strict_types=1);

namespace Modules\Tournaments\Infrastructure\Linking;

use App\Application\Authorization\Services\AuthorizationServiceInterface;
use App\Application\Linking\Contracts\LinkableRecordSourceInterface;
use App\Application\Linking\DTOs\LinkableRecordDTO;
use App\Infrastructure\Persistence\Eloquent\Models\EventModel;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Modules\Tournaments\Filament\Resources\TournamentResource;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\TournamentModel;

/**
 * The module's tournaments as linkable records.
 *
 * Tournaments of the public listing (neither drafts nor cancelled) on a published event are
 * public, so every viewer finds them. Drafts and cancelled ones only for who lists tournaments in the panel (the policy's
 * viewAny). The panel URL is the edit page, given only to who has panel access and may edit it.
 */
final readonly class TournamentLinkableRecordSource implements LinkableRecordSourceInterface
{
    public const string TYPE = 'tournament';

    /** Words of the query that are searched; any beyond are ignored. */
    private const int MAX_QUERY_TERMS = 6;

    /** Backslash escapes the LIKE wildcards; SQLite has no default escape character. */
    private const string NAME_CONTAINS = "LOWER(name) LIKE ? ESCAPE '\\'";

    public function __construct(private AuthorizationServiceInterface $authorization) {}

    public function type(): string
    {
        return self::TYPE;
    }

    public function label(): string
    {
        return __('tournaments::messages.linking.label');
    }

    public function search(string $query, string $viewerId, int $limit): array
    {
        if ($limit < 1) {
            return [];
        }

        $viewer = $this->viewer($viewerId);
        $tournaments = $this->visibleTo($viewer);

        foreach ($this->terms($query) as $term) {
            $tournaments->whereRaw(self::NAME_CONTAINS, ['%'.$this->escapeLike($term).'%']);
        }

        $tournamentsTable = (new TournamentModel)->getTable();
        $eventsTable = (new EventModel)->getTable();

        $found = $tournaments
            ->orderByDesc(EventModel::query()->select('start_date')->whereColumn($eventsTable.'.id', $tournamentsTable.'.event_id'))
            ->orderBy('id')
            ->limit($limit)
            ->get();

        return array_values($found->map(fn (TournamentModel $tournament): LinkableRecordDTO => $this->toRecord($tournament, $viewer))->all());
    }

    public function find(string $id, string $viewerId): ?LinkableRecordDTO
    {
        // Keys are uuids: anything else matches no row, and PostgreSQL rejects it in the query
        if (! Str::isUuid($id)) {
            return null;
        }

        $viewer = $this->viewer($viewerId);
        $tournament = $this->visibleTo($viewer)->whereKey($id)->first();

        return $tournament instanceof TournamentModel ? $this->toRecord($tournament, $viewer) : null;
    }

    /**
     * @return Builder<TournamentModel>
     */
    private function visibleTo(?UserModel $viewer): Builder
    {
        $tournaments = TournamentModel::query()->with('event');

        if (! $viewer instanceof UserModel || ! Gate::forUser($viewer)->allows('viewAny', TournamentModel::class)) {
            // Listed, and on a published event (a proposal under review or a hidden event stays hidden)
            $tournaments->publiclyListed()->whereHas('event', static fn (Builder $event): Builder => $event->where('is_published', true));
        }

        return $tournaments;
    }

    private function viewer(string $viewerId): ?UserModel
    {
        if (! Str::isUuid($viewerId)) {
            return null;
        }

        // Deactivated (soft-deleted) accounts are not found; anonymized ones count as visitors
        $viewer = UserModel::query()->find($viewerId);

        return $viewer instanceof UserModel && $viewer->anonymized_at === null ? $viewer : null;
    }

    private function toRecord(TournamentModel $tournament, ?UserModel $viewer): LinkableRecordDTO
    {
        return new LinkableRecordDTO(
            type: self::TYPE,
            id: $tournament->id,
            title: $tournament->name,
            date: $tournament->event?->start_date->toImmutable(),
            panelUrl: $this->panelUrl($tournament, $viewer),
        );
    }

    private function panelUrl(TournamentModel $tournament, ?UserModel $viewer): ?string
    {
        if (! $viewer instanceof UserModel || ! $this->authorization->can($viewer, 'admin.access')) {
            return null;
        }

        if (! Gate::forUser($viewer)->allows('update', $tournament)) {
            return null;
        }

        return TournamentResource::getUrl('edit', ['record' => $tournament]);
    }

    /**
     * Lower-cased words of the query; every one must appear in the name.
     *
     * @return list<string>
     */
    private function terms(string $query): array
    {
        $words = preg_split('/\s+/u', mb_strtolower(trim($query)), -1, PREG_SPLIT_NO_EMPTY);

        return array_slice($words === false ? [] : $words, 0, self::MAX_QUERY_TERMS);
    }

    private function escapeLike(string $term): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term);
    }
}
