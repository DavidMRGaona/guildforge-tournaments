<?php

declare(strict_types=1);

namespace Modules\Tournaments\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Tournaments\Application\DTOs\Response\TournamentResponseDTO;

/**
 * @property TournamentResponseDTO $resource
 */
final class TournamentResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'name' => $this->resource->name,
            'slug' => $this->resource->slug,
            'description' => $this->resource->description,
            'imagePublicId' => $this->resource->imagePublicId,
            'status' => $this->resource->status->value,
            'statusLabel' => $this->resource->status->label(),
            'statusColor' => $this->resource->status->color(),
            'currentRound' => $this->resource->currentRound,
            'maxRounds' => $this->resource->maxRounds,
            'participantCount' => $this->resource->participantCount,
            'maxParticipants' => $this->resource->maxParticipants,
            'minParticipants' => $this->resource->minParticipants,
            'registrationOpensAt' => $this->resource->registrationOpensAt?->format('c'),
            'registrationClosesAt' => $this->resource->registrationClosesAt?->format('c'),
            'startedAt' => $this->resource->startedAt?->format('c'),
            'completedAt' => $this->resource->completedAt?->format('c'),
            'isRegistrationOpen' => $this->resource->isRegistrationOpen(),
            'isInProgress' => $this->resource->isInProgress(),
            'isFinished' => $this->resource->isFinished(),
            'hasCapacity' => $this->resource->hasCapacity(),
        ];
    }
}
