<?php

declare(strict_types=1);

namespace Modules\Tournaments\Infrastructure\Services;

use App\Application\Services\TimezoneServiceInterface;
use Carbon\CarbonImmutable;
use Modules\Tournaments\Application\Services\GuestConfirmationQueryInterface;
use Modules\Tournaments\Application\Services\GuestRegistrationSettingsInterface;
use Modules\Tournaments\Domain\Entities\Tournament;
use Modules\Tournaments\Domain\Repositories\ParticipantRepositoryInterface;
use Modules\Tournaments\Domain\Repositories\TournamentRepositoryInterface;
use Modules\Tournaments\Domain\Services\Clock;
use Modules\Tournaments\Domain\ValueObjects\TournamentId;

final readonly class GuestConfirmationQuery implements GuestConfirmationQueryInterface
{
    public function __construct(
        private TournamentRepositoryInterface $tournamentRepository,
        private ParticipantRepositoryInterface $participantRepository,
        private GuestRegistrationSettingsInterface $settings,
        private Clock $clock,
    ) {}

    public function forTournament(string $tournamentId): ?array
    {
        $timezone = app(TimezoneServiceInterface::class);

        $tournament = $this->tournamentRepository->find(TournamentId::fromString($tournamentId));

        if (! $tournament instanceof Tournament || ! $tournament->allowGuests()) {
            return null;
        }

        $terms = GuestConfirmationTerms::for(
            $tournament,
            $this->participantRepository->countUnconfirmedHeldByTournament($tournamentId),
            $this->settings,
            CarbonImmutable::instance($this->clock->now()),
        );

        return [
            'mode' => $terms['mode']->value,
            'expires_at' => $timezone->iso($terms['expiresAt']),
        ];
    }
}
