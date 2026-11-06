<?php

declare(strict_types=1);

namespace Modules\Tournaments\Filament\Resources\TournamentResource\Pages;

use App\Filament\Concerns\HasKnowledgeGuideAction;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Modules\Tournaments\Filament\Resources\TournamentResource;

final class ListTournaments extends ListRecords
{
    use HasKnowledgeGuideAction;

    protected static string $resource = TournamentResource::class;

    protected function getHeaderActions(): array
    {
        return array_filter([
            CreateAction::make()
                ->label(__('tournaments::messages.resource.actions.create')),
            $this->knowledgeGuideAction('tournaments.panel.overview'),
        ]);
    }
}
