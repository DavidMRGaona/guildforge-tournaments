<?php

declare(strict_types=1);

namespace Modules\Tournaments\Http\Resources;

use App\Application\Services\TimezoneServiceInterface;
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
        $timezone = app(TimezoneServiceInterface::class);

        return [
            'id' => $this->resource->id,
            'name' => $this->resource->name,
            'slug' => $this->resource->slug,
            'description' => $this->resource->description,
            'imagePublicId' => $this->resource->imagePublicId,
            'status' => $this->resource->status->value,
            'displayStatus' => $this->resource->displayStatus(),
            'statusLabel' => $this->resource->displayStatusLabel(),
            'statusColor' => $this->resource->displayStatusColor(),
            'currentRound' => $this->resource->currentRound,
            'maxRounds' => $this->resource->maxRounds,
            'participantCount' => $this->resource->participantCount,
            'maxParticipants' => $this->resource->maxParticipants,
            'minParticipants' => $this->resource->minParticipants,
            'registrationOpensAt' => $timezone->iso($this->resource->registrationOpensAt),
            'registrationClosesAt' => $timezone->iso($this->resource->registrationClosesAt),
            'startedAt' => $timezone->iso($this->resource->startedAt),
            'completedAt' => $timezone->iso($this->resource->completedAt),
            'isRegistrationOpen' => $this->resource->isRegistrationOpen(),
            'isInProgress' => $this->resource->isInProgress(),
            'isFinished' => $this->resource->isFinished(),
            'hasCapacity' => $this->resource->hasCapacity(),
        ];
    }
}
