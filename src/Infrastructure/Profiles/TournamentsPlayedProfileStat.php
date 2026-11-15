<?php

declare(strict_types=1);

namespace Modules\Tournaments\Infrastructure\Profiles;

use App\Application\Profiles\Contracts\ProfileStatInterface;
use App\Application\Profiles\DTOs\ProfileOwnerDTO;
use App\Application\Profiles\DTOs\ProfileStatDTO;
use Illuminate\Contracts\Auth\Authenticatable;
use Modules\Tournaments\Application\Services\ProfileTournamentQueryServiceInterface;

/**
 * «Torneos jugados»: the finished tournaments the person took part in. Their standings are public, so
 * the count is the same for everybody; nothing played is left out rather than shown as a 0. It has no
 * link: the tournaments' list cannot be filtered by person.
 */
final readonly class TournamentsPlayedProfileStat implements ProfileStatInterface
{
    public const string KEY = 'tournaments.played';

    public function __construct(
        private ProfileTournamentQueryServiceInterface $tournaments,
    ) {}

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return (string) __('tournaments::messages.public_profile.played_label');
    }

    public function order(): int
    {
        return 30;
    }

    public function enabledByDefault(): bool
    {
        return true;
    }

    public function isAvailable(): bool
    {
        return true;
    }

    public function stat(ProfileOwnerDTO $owner, ?Authenticatable $viewer): ?ProfileStatDTO
    {
        $viewerId = $viewer instanceof Authenticatable ? (string) $viewer->getAuthIdentifier() : null;
        $played = $this->tournaments->playedCount($owner->id, $viewerId);

        return $played > 0 ? new ProfileStatDTO($played) : null;
    }
}
