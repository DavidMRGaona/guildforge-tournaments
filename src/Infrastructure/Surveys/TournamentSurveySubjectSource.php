<?php

declare(strict_types=1);

namespace Modules\Tournaments\Infrastructure\Surveys;

use App\Application\Authorization\Services\AuthorizationServiceInterface;
use App\Application\Surveys\Contracts\SurveySubjectAttributeValuesInterface;
use App\Application\Surveys\Contracts\SurveySubjectSourceInterface;
use App\Application\Surveys\DTOs\SurveyAudienceDTO;
use App\Application\Surveys\DTOs\SurveyGuestDTO;
use App\Application\Surveys\DTOs\SurveySubjectAttributeDTO;
use App\Application\Surveys\DTOs\SurveySubjectAttributeValueDTO;
use App\Application\Surveys\DTOs\SurveySubjectDTO;
use App\Application\Surveys\Enums\SurveySubjectStatus;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use DateTimeImmutable;
use Illuminate\Support\Str;
use Modules\Tournaments\Application\Services\TournamentOrganizersInterface;
use Modules\Tournaments\Domain\Entities\GameProfile;
use Modules\Tournaments\Domain\Entities\Participant;
use Modules\Tournaments\Domain\Entities\Tournament;
use Modules\Tournaments\Domain\Enums\TournamentStatus;
use Modules\Tournaments\Domain\Repositories\GameProfileRepositoryInterface;
use Modules\Tournaments\Domain\Repositories\ParticipantRepositoryInterface;
use Modules\Tournaments\Domain\Repositories\TournamentRepositoryInterface;
use Modules\Tournaments\Domain\ValueObjects\GameProfileId;
use Modules\Tournaments\Domain\ValueObjects\PairingConfig;
use Modules\Tournaments\Domain\ValueObjects\TournamentId;

/**
 * Tournaments as survey subjects, through the core's contract: the surveys module, when
 * installed, asks it and nothing here depends on it. Whoever played a round is asked to answer,
 * with an account or as a guest whose email is confirmed; the tournament's organizers and whoever
 * may edit tournaments in the panel manage its surveys. Its game profile is its game system, sent
 * with its id so surveys recognise it after a rename (a template per game system).
 */
final readonly class TournamentSurveySubjectSource implements SurveySubjectAttributeValuesInterface, SurveySubjectSourceInterface
{
    /** Stored by surveys and their statistics: never changes. */
    public const string TYPE = 'tournament';

    private const string UPDATE_PERMISSION = 'tournaments:tournaments.update';

    private const string GAME_SYSTEM = 'game_system';

    public function __construct(
        private TournamentRepositoryInterface $tournaments,
        private GameProfileRepositoryInterface $profiles,
        private ParticipantRepositoryInterface $participants,
        private TournamentOrganizersInterface $organizers,
        private AuthorizationServiceInterface $authorization,
    ) {}

    public function sourceType(): string
    {
        return self::TYPE;
    }

    public function sourceLabel(): string
    {
        return __('tournaments::messages.surveys.source_label');
    }

    public function find(string $subjectId): ?SurveySubjectDTO
    {
        $tournament = $this->tournament($subjectId);
        if (! $tournament instanceof Tournament) {
            return null;
        }

        $profile = $tournament->gameProfileId() !== null ? $this->profiles->find(new GameProfileId($tournament->gameProfileId())) : null;
        // The pairing the rounds use: the tournament's own, else its game profile's, else the default
        $pairing = $tournament->pairingConfig() ?? $profile?->pairingConfig() ?? new PairingConfig;
        // Before it starts, when its event is planned to; never after its end
        $startsAt = $tournament->startedAt() ?? $tournament->eventStartsAt();
        $endsAt = $tournament->completedAt();
        if ($startsAt instanceof DateTimeImmutable && $endsAt instanceof DateTimeImmutable && $endsAt < $startsAt) {
            $startsAt = null;
        }

        return new SurveySubjectDTO(
            sourceType: self::TYPE,
            subjectId: $tournament->id()->value,
            title: $tournament->name(),
            startsAt: $startsAt,
            endsAt: $endsAt,
            organizerIds: $this->organizers->userIds($tournament->id()->value),
            attributes: [
                $profile instanceof GameProfile
                    ? new SurveySubjectAttributeDTO(self::GAME_SYSTEM, __('tournaments::messages.surveys.attributes.game_system'), $profile->name(), $profile->id()->value)
                    : new SurveySubjectAttributeDTO(self::GAME_SYSTEM, __('tournaments::messages.surveys.attributes.game_system'), null),
                new SurveySubjectAttributeDTO('tournament_format', __('tournaments::messages.surveys.attributes.tournament_format'), $pairing->method->label()),
            ],
            url: route('tournaments.show', $tournament->slug()),
            status: match ($tournament->status()) {
                TournamentStatus::Finished => SurveySubjectStatus::Ended,
                TournamentStatus::Cancelled => SurveySubjectStatus::Cancelled,
                default => SurveySubjectStatus::Active,
            },
        );
    }

    public function audience(string $subjectId): SurveyAudienceDTO
    {
        $tournament = $this->tournament($subjectId);
        if (! $tournament instanceof Tournament) {
            return SurveyAudienceDTO::empty();
        }

        $players = $this->participants->findWhoPlayed($tournament->id()->value);

        $accounts = array_values(array_filter(array_map(static fn (Participant $player): ?string => $player->userId(), $players)));
        $active = $accounts === [] ? [] : UserModel::query()
            ->whereIn('id', $accounts)
            ->whereNull('anonymized_at')
            ->pluck('id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->all();

        return new SurveyAudienceDTO(
            userIds: array_values(array_filter($accounts, static fn (string $id): bool => in_array($id, $active, true))),
            guests: array_values(array_map(
                static fn (Participant $guest): SurveyGuestDTO => new SurveyGuestDTO(
                    reference: $guest->id()->value,
                    displayName: self::guestName($guest),
                    email: (string) $guest->guestEmail(),
                ),
                array_filter($players, $this->isReachableGuest(...)),
            )),
        );
    }

    public function canManage(string $userId, string $subjectId): bool
    {
        $tournament = $this->tournament($subjectId);
        if (! $tournament instanceof Tournament) {
            return false;
        }

        if ($this->organizers->isOrganizer($tournament->id()->value, $userId)) {
            return true;
        }

        // Whoever may edit tournaments in the panel acts for the organizers
        $user = UserModel::query()->find($userId);

        return $user instanceof UserModel && $this->authorization->can($user, self::UPDATE_PERMISSION);
    }

    /**
     * Every game profile, by name, as the game systems its tournaments can be of.
     */
    public function attributeValues(string $key): array
    {
        if ($key !== self::GAME_SYSTEM) {
            return [];
        }

        $profiles = $this->profiles->findAll();
        usort($profiles, static fn (GameProfile $a, GameProfile $b): int => strnatcasecmp($a->name(), $b->name()));

        return array_map(
            static fn (GameProfile $profile): SurveySubjectAttributeValueDTO => new SurveySubjectAttributeValueDTO($profile->id()->value, $profile->name()),
            $profiles,
        );
    }

    private function tournament(string $subjectId): ?Tournament
    {
        return Str::isUuid($subjectId) ? $this->tournaments->find(new TournamentId($subjectId)) : null;
    }

    /**
     * A guest the survey can reach: no account, and an email they confirmed that is a valid
     * address (an invalid one would make the whole audience fail).
     */
    private function isReachableGuest(Participant $player): bool
    {
        return $player->isGuest()
            && $player->emailConfirmedAt() instanceof DateTimeImmutable
            && filter_var((string) $player->guestEmail(), FILTER_VALIDATE_EMAIL) !== false;
    }

    private static function guestName(Participant $guest): string
    {
        $name = trim((string) $guest->guestName());

        return $name !== '' ? $name : (string) $guest->guestEmail();
    }
}
