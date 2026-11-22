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
 * Who played each finished tournament and where they finished, for whoever builds on it (core#774,
 * the achievements module).
 *
 * What counts (only what any signed-in member can know exists, the same answer for everybody: it
 * runs in queued jobs, where nobody is signed in):
 *
 * - a finished tournament of the public listing whose event is published, and that has an end
 *   date; never a draft, a cancelled one or one of an unpublished event;
 * - a player is a participant with an account who is confirmed or checked in now and took part in
 *   a match that was not left unplayed (a bye counts: it is a round). One who withdrew or was
 *   disqualified is not reported, even after playing rounds, and neither is a guest without an
 *   account or somebody registered who never played a match;
 * - the placement is the public table's (FinalPlacementRule), worked out over every row of the
 *   final standings whoever it belongs to and whatever their status now: 1 is the winner, rows
 *   level on points and on the configured tiebreakers share it. Nobody moves up because another
 *   player withdrew or was disqualified, and a guest who played holds their place too: if the
 *   player leading the table withdrew, the second one is still second and the first gets nothing;
 * - a tournament with nobody to report counts for nothing.
 *
 * Only players are reported. Who organizes a tournament is chosen in the panel and no page shows
 * it, so it is not something a member can know: an organizer role can be added when the tournament
 * shows its organization in public.
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

        return $this->factsOf(array_values($tournaments->all()))[Str::lower($subjectId)] ?? [];
    }

    public function history(?string $cursor, int $limit): ActivityHistoryPageDTO
    {
        $after = $cursor === null ? null : $this->decode($cursor);
        $limit = max(1, min($limit, ActivityHistoryPageDTO::MAX_LIMIT));

        // A finished tournament nobody played has no facts and is left out: read one more than
        // the page holds, so the last page says it is the last
        $subjects = [];
        while (count($subjects) <= $limit) {
            $tournaments = array_values(TournamentModel::query()
                ->finishedAndShown()
                ->with('gameProfile')
                ->when($after !== null, static fn ($query) => $query->where('id', '>', $after))
                ->orderBy('id')
                ->limit($limit + 1)
                ->get()
                ->all());

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
        // Stricter than the public page (finished tournaments of a published event only) and the
        // same for everybody, guests included: the viewer changes nothing
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
     * The facts of each tournament, in batch: three queries however many there are.
     *
     * @param  list<TournamentModel>  $tournaments
     * @return array<string, list<ActivityFactDTO>> By tournament id; one with nobody to report has none
     */
    private function factsOf(array $tournaments): array
    {
        if ($tournaments === []) {
            return [];
        }

        $ids = array_map(static fn (TournamentModel $tournament): string => $tournament->id, $tournaments);
        $standings = $this->standingsOf($ids, $this->participantsWhoPlayed($ids));

        $facts = [];
        foreach ($tournaments as $tournament) {
            $facts[$tournament->id] = $this->build($tournament, $standings[$tournament->id] ?? []);
        }

        return $facts;
    }

    /**
     * @param  list<array{standing: FinalStanding, userId: string|null, reported: bool}>  $rows
     * @return list<ActivityFactDTO>
     */
    private function build(TournamentModel $tournament, array $rows): array
    {
        // Without an end date there is no moment to report: never made up from the last save
        $occurredAt = $tournament->completed_at?->toDateTimeImmutable();
        if ($occurredAt === null) {
            return [];
        }

        $placements = $this->placements->placements(array_map(static fn (array $row): FinalStanding => $row['standing'], $rows));
        $profile = $tournament->gameProfile;
        $attributes = $profile instanceof GameProfileModel
            ? [self::ATTRIBUTE_PROFILE => new ActivityAttributeValueDTO($profile->id, $profile->name)]
            : [];

        $players = [];
        foreach ($rows as $row) {
            if ($row['reported'] && $row['userId'] !== null) {
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

        return $players;
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
     * Every row of the final standings by tournament, and whether it is reported: the participant
     * is confirmed or checked in and played. The rest still hold their place in the table.
     *
     * The ties are told by what the table orders by: the points and the tiebreakers the tournament
     * configures (`calculated_tiebreakers`); the other columns are only shown.
     *
     * @param  list<string>  $tournamentIds
     * @param  array<string, true>  $played
     * @return array<string, list<array{standing: FinalStanding, userId: string|null, reported: bool}>>
     */
    private function standingsOf(array $tournamentIds, array $played): array
    {
        $rows = [];
        $reportable = [ParticipantStatus::Confirmed, ParticipantStatus::CheckedIn];

        $standings = StandingModel::query()
            ->whereIn('tournament_id', $tournamentIds)
            ->with('participant')
            ->get();

        foreach ($standings as $standing) {
            $rows[$standing->tournament_id][] = [
                'standing' => new FinalStanding(
                    $standing->participant_id,
                    $standing->rank,
                    $standing->points,
                    array_map(floatval(...), $standing->calculated_tiebreakers ?? []),
                ),
                'userId' => $standing->participant->user_id,
                'reported' => isset($played[$standing->participant_id]) && in_array($standing->participant->status, $reportable, true),
            ];
        }

        return $rows;
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
