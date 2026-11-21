<?php

declare(strict_types=1);

namespace Modules\Tournaments\Infrastructure\Activity;

use App\Application\Activity\Contracts\ActivitySourceInterface;
use App\Application\Activity\Contracts\OffersActivityAttributes;
use App\Application\Activity\DTOs\ActivityAttributeDTO;
use App\Application\Activity\DTOs\ActivityAttributeValueDTO;
use App\Application\Activity\DTOs\ActivityFactDTO;
use App\Application\Activity\DTOs\ActivityHistoryPageDTO;
use App\Application\Activity\DTOs\ActivitySubjectDTO;
use App\Application\Activity\DTOs\ActivitySubjectFactsDTO;
use App\Domain\Activity\ActivityKind;
use App\Domain\Activity\ActivityRole;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Modules\Tournaments\Domain\Enums\MatchResult;
use Modules\Tournaments\Domain\Enums\ParticipantStatus;
use Modules\Tournaments\Domain\Services\FinalPlacementRule;
use Modules\Tournaments\Domain\ValueObjects\FinalStanding;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\GameProfileModel;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\StandingModel;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\TournamentModel;

/**
 * Who took part in each finished tournament, for whoever builds on it (core#774, the achievements
 * module): the people who played, with where they finished, and the organization.
 *
 * What counts (only what any signed-in member can know exists, the same answer for everybody: it
 * runs in queued jobs, where nobody is signed in):
 *
 * - a finished tournament of the public listing whose event is published; never a draft, a
 *   cancelled one or one of an unpublished event;
 * - a player is a participant who can play (confirmed or checked in), is in the final standings
 *   and took part in a match that was not left unplayed (a bye counts: it is a round). A player
 *   who withdrew or was disqualified is never reported, even after playing rounds: they left the
 *   competition and their record says nothing about how they did. Neither is a guest without an
 *   account. Somebody registered who never played a match is not a player;
 * - a tournament nobody played (no round was ever played) counts for nothing, the organization
 *   included: nothing took place;
 * - the placement is the table's (FinalPlacementRule): 1 is the winner, rows level on points and
 *   tiebreakers share it. Those left out above hold no place, so the next one moves up; a guest
 *   who played does, since they really finished there;
 * - the organization is whoever the panel lists as the tournament's organizers.
 *
 * Every fact carries the tournament's game profile (its stable id and its name) as the attribute
 * `tournament_profile`, when it has one. The moment is the tournament's end.
 */
final readonly class TournamentActivitySource implements ActivitySourceInterface, OffersActivityAttributes
{
    /** Stored by whoever builds on it: never changes. */
    public const string TYPE = 'tournaments.tournament';

    public const string ATTRIBUTE_PROFILE = 'tournament_profile';

    private const string CURSOR_PREFIX = 'tn1|';

    public function __construct(private FinalPlacementRule $placements) {}

    public function type(): string
    {
        return self::TYPE;
    }

    public function kind(): ActivityKind
    {
        return ActivityKind::Tournament;
    }

    public function label(): string
    {
        return __('tournaments::messages.activity.source_label');
    }

    public function facts(string $subjectId): array
    {
        if (! Str::isUuid($subjectId)) {
            return [];
        }

        $tournaments = TournamentModel::query()->finishedAndShown()->with('gameProfile')->whereKey(Str::lower($subjectId))->get();

        return $this->factsOf($tournaments->all())[Str::lower($subjectId)] ?? [];
    }

    public function history(?string $cursor, int $limit): ActivityHistoryPageDTO
    {
        $after = $cursor === null ? null : $this->decode($cursor);
        $limit = max(1, min($limit, ActivityHistoryPageDTO::MAX_LIMIT));

        // A finished tournament nobody played has no facts and is left out: read one more than
        // the page holds, so the last page says it is the last
        $subjects = [];
        while (count($subjects) <= $limit) {
            $tournaments = TournamentModel::query()
                ->finishedAndShown()
                ->with('gameProfile')
                ->when($after !== null, static fn ($query) => $query->where('id', '>', $after))
                ->orderBy('id')
                ->limit($limit + 1)
                ->get()
                ->all();

            if ($tournaments === []) {
                break;
            }

            $facts = $this->factsOf($tournaments);
            foreach ($tournaments as $tournament) {
                $after = $tournament->id;
                if (($facts[$tournament->id] ?? []) !== []) {
                    $subjects[] = new ActivitySubjectFactsDTO($tournament->id, $facts[$tournament->id]);
                }
            }
        }

        if (count($subjects) <= $limit) {
            return new ActivityHistoryPageDTO($subjects, null);
        }

        $page = array_slice($subjects, 0, $limit);

        return new ActivityHistoryPageDTO($page, $this->encode($page[$limit - 1]->subjectId));
    }

    public function describe(array $subjectIds, ?Authenticatable $viewer): array
    {
        // The public page is the same for everybody, guests included: the viewer changes nothing
        $ids = array_values(array_unique(array_map(Str::lower(...), array_filter($subjectIds, Str::isUuid(...)))));
        if ($ids === []) {
            return [];
        }

        $described = [];
        foreach (TournamentModel::query()->finishedAndShown()->whereKey($ids)->get() as $tournament) {
            $described[$tournament->id] = new ActivitySubjectDTO(
                $tournament->id,
                $tournament->name,
                route('tournaments.show', $tournament->slug, false),
            );
        }

        return $described;
    }

    public function attributes(): array
    {
        return [new ActivityAttributeDTO(self::ATTRIBUTE_PROFILE, __('tournaments::messages.activity.attributes.tournament_profile'))];
    }

    /**
     * Every game profile, by name.
     */
    public function attributeValues(string $key): array
    {
        if ($key !== self::ATTRIBUTE_PROFILE) {
            return [];
        }

        $profiles = GameProfileModel::query()->get()->all();
        usort($profiles, static fn (GameProfileModel $a, GameProfileModel $b): int => strnatcasecmp($a->name, $b->name));

        return array_map(
            static fn (GameProfileModel $profile): ActivityAttributeValueDTO => new ActivityAttributeValueDTO($profile->id, $profile->name),
            $profiles,
        );
    }

    /**
     * The facts of each tournament, in batch: four queries however many there are.
     *
     * @param  list<TournamentModel>  $tournaments
     * @return array<string, list<ActivityFactDTO>> By tournament id; a tournament nobody played has none
     */
    private function factsOf(array $tournaments): array
    {
        if ($tournaments === []) {
            return [];
        }

        $ids = array_map(static fn (TournamentModel $tournament): string => $tournament->id, $tournaments);
        $played = $this->participantsWhoPlayed($ids);
        $standings = $this->standingsOfThoseWhoPlayed($ids, $played);
        $organizers = $this->organizersOf($ids);

        $facts = [];
        foreach ($tournaments as $tournament) {
            $rows = $standings[$tournament->id] ?? [];
            $facts[$tournament->id] = $rows === [] ? [] : $this->build($tournament, $rows, $organizers[$tournament->id] ?? []);
        }

        return $facts;
    }

    /**
     * @param  list<array{standing: FinalStanding, userId: string|null}>  $rows
     * @param  list<string>  $organizerIds
     * @return list<ActivityFactDTO>
     */
    private function build(TournamentModel $tournament, array $rows, array $organizerIds): array
    {
        $placements = $this->placements->placements(array_map(static fn (array $row): FinalStanding => $row['standing'], $rows));
        $profile = $tournament->gameProfile;
        $attributes = $profile instanceof GameProfileModel
            ? [self::ATTRIBUTE_PROFILE => new ActivityAttributeValueDTO($profile->id, $profile->name)]
            : [];
        $occurredAt = ($tournament->completed_at ?? $tournament->updated_at ?? $tournament->created_at)?->toDateTimeImmutable() ?? throw new InvalidArgumentException('A finished tournament has no date.');

        $players = [];
        foreach ($rows as $row) {
            if ($row['userId'] !== null) {
                $players[] = new ActivityFactDTO(
                    $row['userId'],
                    ActivityRole::Player,
                    $occurredAt,
                    $attributes,
                    $placements[$row['standing']->participantId],
                );
            }
        }
        usort($players, static fn (ActivityFactDTO $a, ActivityFactDTO $b): int => [$a->placement, $a->userId] <=> [$b->placement, $b->userId]);

        $organization = array_map(
            static fn (string $userId): ActivityFactDTO => new ActivityFactDTO($userId, ActivityRole::Organizer, $occurredAt, $attributes),
            $organizerIds,
        );

        return [...$players, ...$organization];
    }

    /**
     * The participants who took part in a match of their tournament that was not left unplayed.
     *
     * @param  list<string>  $tournamentIds
     * @return array<string, true> By participant id
     */
    private function participantsWhoPlayed(array $tournamentIds): array
    {
        $played = [];

        $matches = DB::table('tournaments_matches as matches')
            ->join('tournaments_rounds as rounds', 'rounds.id', '=', 'matches.round_id')
            ->whereIn('rounds.tournament_id', $tournamentIds)
            ->where('matches.result', '!=', MatchResult::NotPlayed->value)
            ->get(['matches.player_1_id', 'matches.player_2_id']);

        foreach ($matches as $match) {
            $played[(string) $match->player_1_id] = true;
            if ($match->player_2_id !== null) {
                $played[(string) $match->player_2_id] = true;
            }
        }

        return $played;
    }

    /**
     * The final standings of the participants who can play and played, by tournament.
     *
     * @param  list<string>  $tournamentIds
     * @param  array<string, true>  $played
     * @return array<string, list<array{standing: FinalStanding, userId: string|null}>>
     */
    private function standingsOfThoseWhoPlayed(array $tournamentIds, array $played): array
    {
        $rows = [];

        $standings = StandingModel::query()
            ->whereIn('tournament_id', $tournamentIds)
            ->whereHas('participant', static fn ($participant) => $participant->whereIn('status', [ParticipantStatus::Confirmed->value, ParticipantStatus::CheckedIn->value]))
            ->with('participant')
            ->get();

        foreach ($standings as $standing) {
            if (! isset($played[$standing->participant_id])) {
                continue;
            }

            $rows[$standing->tournament_id][] = [
                'standing' => new FinalStanding(
                    $standing->participant_id,
                    $standing->rank,
                    $standing->points,
                    [
                        ...array_map(floatval(...), $standing->calculated_tiebreakers ?? []),
                        '_buchholz' => $standing->buchholz,
                        '_median_buchholz' => $standing->median_buchholz,
                        '_progressive' => $standing->progressive,
                        '_opponent_win_percentage' => $standing->opponent_win_percentage,
                    ],
                ),
                'userId' => $standing->participant->user_id,
            ];
        }

        return $rows;
    }

    /**
     * @param  list<string>  $tournamentIds
     * @return array<string, list<string>> Organizer account ids by tournament, in order
     */
    private function organizersOf(array $tournamentIds): array
    {
        $organizers = [];

        $rows = DB::table('tournaments_organizers')
            ->whereIn('tournament_id', $tournamentIds)
            ->orderBy('user_id')
            ->get(['tournament_id', 'user_id']);

        foreach ($rows as $row) {
            $organizers[(string) $row->tournament_id][] = (string) $row->user_id;
        }

        return $organizers;
    }

    private function encode(string $tournamentId): string
    {
        return rtrim(strtr(base64_encode(self::CURSOR_PREFIX.$tournamentId), '+/', '-_'), '=');
    }

    private function decode(string $cursor): string
    {
        $decoded = base64_decode(strtr($cursor, '-_', '+/'), true);

        if (! is_string($decoded) || ! str_starts_with($decoded, self::CURSOR_PREFIX)) {
            throw new InvalidArgumentException('The history cursor cannot be read.');
        }

        $id = substr($decoded, strlen(self::CURSOR_PREFIX));
        if (! Str::isUuid($id)) {
            throw new InvalidArgumentException('The history cursor cannot be read.');
        }

        return Str::lower($id);
    }
}
