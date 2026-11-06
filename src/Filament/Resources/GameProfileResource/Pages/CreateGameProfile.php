<?php

declare(strict_types=1);

namespace Modules\Tournaments\Filament\Resources\GameProfileResource\Pages;

use App\Filament\Concerns\HasKnowledgeGuideAction;
use Filament\Actions\Action;
use Filament\Resources\Pages\CreateRecord;
use Modules\Tournaments\Filament\Resources\GameProfileResource;

final class CreateGameProfile extends CreateRecord
{
    use HasKnowledgeGuideAction;

    protected static string $resource = GameProfileResource::class;

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return array_filter([
            $this->knowledgeGuideAction('tournaments.panel.game-profiles'),
        ]);
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // Always set is_system to false for new profiles
        $data['is_system'] = false;

        return $data;
    }
}
