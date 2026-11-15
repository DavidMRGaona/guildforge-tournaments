<?php

declare(strict_types=1);

namespace Modules\Tournaments\Application\DTOs;

use App\Application\Services\TimezoneServiceInterface;
use DateTimeImmutable;
use Modules\Tournaments\Domain\Enums\TournamentStatus;

/**
 * A tournament on someone's public profile: what the tournament's own page already shows about the
 * person (their place and points in the standings, the round under way) and nothing else.
 */
final readonly class ProfileTournamentEntryDTO
{
    public function __construct(
        public string $id,
        public string $name,
        public string $slug,
        public TournamentStatus $status,
        public ?string $eventTitle,
        public ?DateTimeImmutable $startsAt,
        public ?int $round,
        public ?int $position,
        public ?float $points,
    ) {}

    /**
     * The plain data the Vue component draws; the link is the tournament's own route and the date
     * an instant in the display zone.
     *
     * @return array{id: string, name: string, url: string, eventTitle: string|null, startsAt: string|null, round: int|null, position: int|null, points: float|null}
     */
    public function toArray(TimezoneServiceInterface $timezone): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'url' => route('tournaments.show', $this->slug, false),
            'eventTitle' => $this->eventTitle,
            'startsAt' => $timezone->iso($this->startsAt),
            'round' => $this->round,
            'position' => $this->position,
            'points' => $this->points,
        ];
    }
}
