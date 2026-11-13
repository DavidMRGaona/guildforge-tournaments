<?php

declare(strict_types=1);

namespace Modules\Tournaments\Infrastructure\Proposals;

use App\Application\Proposals\Contracts\EventProposalRequestInterface;
use App\Application\Proposals\DTOs\ProposalRequestFieldDTO;
use App\Domain\Proposals\Exceptions\ProposalRequestRefusedException;
use App\Domain\ValueObjects\EventId;
use App\Infrastructure\Persistence\Eloquent\Models\EventModel;
use Modules\Tournaments\Domain\Entities\GameProfile;
use Modules\Tournaments\Domain\Entities\Tournament;
use Modules\Tournaments\Domain\Enums\ResultReporting;
use Modules\Tournaments\Domain\Enums\TournamentStatus;
use Modules\Tournaments\Domain\Repositories\GameProfileRepositoryInterface;
use Modules\Tournaments\Domain\Repositories\TournamentRepositoryInterface;
use Modules\Tournaments\Domain\ValueObjects\GameProfileId;
use Modules\Tournaments\Domain\ValueObjects\PairingConfig;
use Modules\Tournaments\Domain\ValueObjects\TournamentId;
use Modules\Tournaments\Filament\TournamentFormDefaults;
use Modules\Tournaments\Infrastructure\Services\TournamentDefaultRules;

/**
 * «¿Habrá torneo?» in an event proposal (core#581). On approval it creates the event's tournament
 * as a draft: named after the event, with the chosen game profile and places, and everything else
 * as the module's defaults, for the administration to complete in the panel.
 */
final readonly class TournamentProposalRequest implements EventProposalRequestInterface
{
    public const string KEY = 'tournaments.tournament';

    private const int MIN_PLACES = 2;

    private const int MAX_PLACES = 1000;

    public function __construct(
        private TournamentRepositoryInterface $tournaments,
        private GameProfileRepositoryInterface $profiles,
    ) {}

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return __('tournaments::messages.proposal_request.label');
    }

    public function question(): string
    {
        return __('tournaments::messages.proposal_request.question');
    }

    public function order(): int
    {
        return 30;
    }

    public function fields(): array
    {
        return [
            ProposalRequestFieldDTO::select(
                'game_profile',
                __('tournaments::messages.proposal_request.game_profile'),
                $this->profileOptions(),
                helperText: __('tournaments::messages.proposal_request.game_profile_help'),
            ),
            ProposalRequestFieldDTO::number(
                'places',
                __('tournaments::messages.proposal_request.places'),
                min: self::MIN_PLACES,
                max: self::MAX_PLACES,
                helperText: __('tournaments::messages.proposal_request.places_help'),
            ),
        ];
    }

    public function isAvailable(): bool
    {
        return true;
    }

    public function apply(EventId $eventId, array $values): void
    {
        if ($this->tournaments->findByEventId($eventId->value()) instanceof Tournament) {
            throw ProposalRequestRefusedException::because(__('tournaments::messages.proposal_request.already_configured'));
        }

        // An event still in review is a proposal: the global scope only hides the unsent drafts
        $event = EventModel::query()->includingUnsubmittedProposals()->find($eventId->value(), ['id', 'title']);
        if (! $event instanceof EventModel) {
            throw ProposalRequestRefusedException::because(__('tournaments::messages.proposal_request.event_missing'));
        }

        $profileId = $values['game_profile'] ?? null;
        $profile = is_string($profileId) ? $this->profiles->find(GameProfileId::fromString($profileId)) : null;
        if (is_string($profileId) && ! $profile instanceof GameProfile) {
            throw ProposalRequestRefusedException::because(__('tournaments::messages.proposal_request.profile_missing'));
        }

        $places = $values['places'] ?? null;

        // The slug stays empty: the model gives it a unique one derived from the name
        $this->tournaments->save(new Tournament(
            id: TournamentId::generate(),
            eventId: $eventId->value(),
            name: $event->title,
            slug: '',
            status: TournamentStatus::Draft,
            scoreWeights: [],
            tiebreakers: [],
            resultReporting: ResultReporting::tryFrom(TournamentFormDefaults::resultReporting()) ?? ResultReporting::AdminOnly,
            maxParticipants: is_int($places) ? $places : null,
            requiresCheckIn: TournamentFormDefaults::requiresCheckIn(),
            checkInStartsBefore: TournamentFormDefaults::requiresCheckIn() ? TournamentFormDefaults::checkInStartsBefore() : null,
            allowGuests: TournamentFormDefaults::allowGuests(),
            gameProfileId: $profile?->id()->value,
            statDefinitions: $profile?->statDefinitions(),
            scoringRules: $profile instanceof GameProfile ? $profile->scoringRules() : TournamentDefaultRules::scoringRuleObjects(),
            tiebreakerConfig: $profile instanceof GameProfile ? $profile->tiebreakerConfig() : TournamentDefaultRules::tiebreakerObjects(),
            pairingConfig: $profile instanceof GameProfile ? $profile->pairingConfig() : new PairingConfig,
        ));
    }

    public function describe(array $values): string
    {
        $profileId = $values['game_profile'] ?? null;
        $profile = is_string($profileId) ? $this->profiles->find(GameProfileId::fromString($profileId)) : null;
        $places = $values['places'] ?? null;

        if (! $profile instanceof GameProfile && ! is_int($places)) {
            return __('tournaments::messages.proposal_request.describe_plain');
        }

        return __('tournaments::messages.proposal_request.describe', [
            'profile' => $profile instanceof GameProfile ? $profile->name() : __('tournaments::messages.proposal_request.no_profile'),
            'places' => is_int($places) ? (string) $places : __('tournaments::messages.proposal_request.no_limit'),
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function profileOptions(): array
    {
        $options = [];
        foreach ($this->profiles->findAll() as $profile) {
            $options[$profile->id()->value] = $profile->name();
        }
        asort($options);

        return $options;
    }
}
