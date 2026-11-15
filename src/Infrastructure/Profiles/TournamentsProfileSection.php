<?php

declare(strict_types=1);

namespace Modules\Tournaments\Infrastructure\Profiles;

use App\Application\Exceptions\NothingToDrawException;
use App\Application\Profiles\Contracts\ProfileSectionInterface;
use App\Application\Profiles\DTOs\ProfileOwnerDTO;
use App\Application\Services\TimezoneServiceInterface;
use App\Domain\Profiles\ProfileRegion;
use Illuminate\Contracts\Auth\Authenticatable;
use Modules\Tournaments\Application\DTOs\ProfileTournamentEntryDTO;
use Modules\Tournaments\Application\Services\ProfileTournamentQueryServiceInterface;
use Modules\Tournaments\Domain\Enums\TournamentStatus;

/**
 * «Torneos» on the public profile: the tournaments under way and the next ones, with the person's
 * place or round when there is one, and the latest results. What each viewer gets is decided by
 * ProfileTournamentQueryServiceInterface with the rule of the tournament's own page.
 */
final readonly class TournamentsProfileSection implements ProfileSectionInterface
{
    public const string KEY = 'tournaments.tournaments';

    private const int CURRENT_LIMIT = 10;

    private const int RECENT_LIMIT = 5;

    public function __construct(
        private ProfileTournamentQueryServiceInterface $tournaments,
        private TimezoneServiceInterface $timezone,
    ) {}

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return (string) __('tournaments::messages.public_profile.section_label');
    }

    public function description(): string
    {
        return (string) __('tournaments::messages.public_profile.section_description');
    }

    public function component(): string
    {
        return 'components/profile/PublicProfileTournamentsSection.vue';
    }

    public function region(): ProfileRegion
    {
        return ProfileRegion::Main;
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

    public function props(ProfileOwnerDTO $owner, ?Authenticatable $viewer): array
    {
        $viewerId = $viewer instanceof Authenticatable ? (string) $viewer->getAuthIdentifier() : null;

        $current = $this->tournaments->current($owner->id, $viewerId, self::CURRENT_LIMIT);
        $recent = $this->tournaments->recent($owner->id, $viewerId, self::RECENT_LIMIT);

        if ($current === [] && $recent === []) {
            throw NothingToDrawException::make();
        }

        return [
            'ongoing' => $this->drawn(array_filter($current, static fn (ProfileTournamentEntryDTO $entry): bool => $entry->status === TournamentStatus::InProgress)),
            'upcoming' => $this->drawn(array_filter($current, static fn (ProfileTournamentEntryDTO $entry): bool => $entry->status !== TournamentStatus::InProgress)),
            'recent' => $this->drawn($recent),
        ];
    }

    /**
     * @param  array<ProfileTournamentEntryDTO>  $entries
     * @return list<array<string, mixed>>
     */
    private function drawn(array $entries): array
    {
        return array_values(array_map(
            fn (ProfileTournamentEntryDTO $entry): array => $entry->toArray($this->timezone),
            $entries,
        ));
    }
}
