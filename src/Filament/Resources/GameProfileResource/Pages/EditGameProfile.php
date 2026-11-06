<?php

declare(strict_types=1);

namespace Modules\Tournaments\Filament\Resources\GameProfileResource\Pages;

use App\Filament\Concerns\HasKnowledgeGuideAction;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Modules\Tournaments\Filament\Resources\GameProfileResource;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\GameProfileModel;

final class EditGameProfile extends EditRecord
{
    use HasKnowledgeGuideAction;

    protected static string $resource = GameProfileResource::class;

    protected function getHeaderActions(): array
    {
        return array_filter([
            DeleteAction::make()
                ->visible(fn (GameProfileModel $record): bool => ! $record->is_system),
            $this->knowledgeGuideAction('tournaments.panel.game-profiles'),
        ]);
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        // For system profiles, only allow description to be updated
        if ($this->record instanceof GameProfileModel && $this->record->is_system) {
            return [
                'description' => $data['description'] ?? $this->record->description,
            ];
        }

        return $data;
    }
}
