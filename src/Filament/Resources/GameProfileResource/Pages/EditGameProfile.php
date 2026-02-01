<?php

declare(strict_types=1);

namespace Modules\Tournaments\Filament\Resources\GameProfileResource\Pages;

use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Modules\Tournaments\Filament\Resources\GameProfileResource;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\GameProfileModel;

final class EditGameProfile extends EditRecord
{
    protected static string $resource = GameProfileResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->visible(fn (GameProfileModel $record): bool => !$record->is_system),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        // For system profiles, only allow description to be updated
        if ($this->record instanceof GameProfileModel && $this->record->is_system) {
            $data = [
                'description' => $data['description'] ?? $this->record->description,
            ];
        }

        return $data;
    }
}
