<?php

declare(strict_types=1);

namespace Modules\Tournaments\Filament\Resources\TournamentResource\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Modules\Tournaments\Filament\Resources\TournamentResource;

final class ListTournaments extends ListRecords
{
    protected static string $resource = TournamentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label(__('tournaments::messages.resource.actions.create')),
        ];
    }
}
