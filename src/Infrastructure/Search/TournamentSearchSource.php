<?php

declare(strict_types=1);

namespace Modules\Tournaments\Infrastructure\Search;

use App\Application\Search\Contracts\IndexedSearchSourceInterface;
use App\Application\Search\DTOs\SearchDocumentDTO;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Modules\Tournaments\Domain\Enums\TournamentStatus;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\TournamentModel;

/**
 * The tournaments of the public listing (neither drafts nor cancelled), finished ones included and
 * marked. Dates and place come from the tournament's event; the game is its game profile.
 */
final class TournamentSearchSource implements IndexedSearchSourceInterface
{
    public const string TYPE = 'tournaments.tournaments';

    public function type(): string
    {
        return self::TYPE;
    }

    public function label(): string
    {
        return __('tournaments::messages.search.label');
    }

    public function itemLabel(): string
    {
        return __('tournaments::messages.search.item');
    }

    public function pastLabel(): string
    {
        return __('tournaments::messages.search.past');
    }

    public function document(string $id): ?SearchDocumentDTO
    {
        // Keys are uuids: anything else matches no row, and PostgreSQL rejects it in the query
        if (! Str::isUuid($id)) {
            return null;
        }

        $tournament = $this->listed()->whereKey($id)->first();

        return $tournament instanceof TournamentModel ? $this->toDocument($tournament) : null;
    }

    /**
     * @return iterable<SearchDocumentDTO>
     */
    public function documents(): iterable
    {
        foreach ($this->listed()->lazyById(200) as $tournament) {
            yield $this->toDocument($tournament);
        }
    }

    /**
     * @return Builder<TournamentModel>
     */
    private function listed(): Builder
    {
        return TournamentModel::query()->publiclyListed()->with(['event', 'gameProfile']);
    }

    private function toDocument(TournamentModel $tournament): SearchDocumentDTO
    {
        $event = $tournament->event;

        return new SearchDocumentDTO(
            id: $tournament->id,
            title: $tournament->name,
            body: $this->plain($tournament->description),
            url: route('tournaments.show', $tournament->slug, false),
            keywords: $this->join([$tournament->gameProfile?->name], ' '),
            meta: $this->join([$event?->title, $event?->location], ' · '),
            imagePublicId: $tournament->image_public_id ?? $event?->image_public_id,
            startsAt: $event?->start_date->toImmutable(),
            endsAt: $event?->end_date->toImmutable(),
            // Over by status; being over by date comes from the event's dates when searching
            finished: $tournament->status === TournamentStatus::Finished,
        );
    }

    private function plain(?string $text): string
    {
        $plain = html_entity_decode(strip_tags($text ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim(preg_replace('/[\s\x{00A0}]+/u', ' ', $plain) ?? $plain);
    }

    /**
     * The non-empty parts joined by the separator, or null when none is left.
     *
     * @param  list<string|null>  $parts
     */
    private function join(array $parts, string $separator): ?string
    {
        $filled = array_filter($parts, static fn (?string $part): bool => $part !== null && trim($part) !== '');

        return $filled === [] ? null : implode($separator, array_map(trim(...), $filled));
    }
}
