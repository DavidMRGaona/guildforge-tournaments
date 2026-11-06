<?php

declare(strict_types=1);

namespace Modules\Tournaments\Filament\Resources\GameProfileResource\Pages;

use App\Filament\Concerns\HasKnowledgeGuideAction;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Modules\Tournaments\Filament\Resources\GameProfileResource;

final class ListGameProfiles extends ListRecords
{
    use HasKnowledgeGuideAction;

    protected static string $resource = GameProfileResource::class;

    protected function getHeaderActions(): array
    {
        return array_filter([
            CreateAction::make(),
            $this->knowledgeGuideAction('tournaments.panel.game-profiles'),
        ]);
    }
}
