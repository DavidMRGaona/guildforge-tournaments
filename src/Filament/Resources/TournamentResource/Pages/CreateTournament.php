<?php

declare(strict_types=1);

namespace Modules\Tournaments\Filament\Resources\TournamentResource\Pages;

use Filament\Resources\Pages\CreateRecord;
use Illuminate\Contracts\Support\Htmlable;
use Modules\Tournaments\Domain\Enums\TournamentStatus;
use Modules\Tournaments\Filament\Resources\TournamentResource;

final class CreateTournament extends CreateRecord
{
    protected static string $resource = TournamentResource::class;

    public function getTitle(): string|Htmlable
    {
        return __('tournaments::messages.resource.actions.create');
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['status'] = TournamentStatus::Draft->value;
        $data['current_round'] = 0;

        return $data;
    }
}
