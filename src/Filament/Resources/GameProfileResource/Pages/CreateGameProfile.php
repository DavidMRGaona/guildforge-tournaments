<?php

declare(strict_types=1);

namespace Modules\Tournaments\Filament\Resources\GameProfileResource\Pages;

use Filament\Resources\Pages\CreateRecord;
use Modules\Tournaments\Filament\Resources\GameProfileResource;

final class CreateGameProfile extends CreateRecord
{
    protected static string $resource = GameProfileResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // Always set is_system to false for new profiles
        $data['is_system'] = false;

        return $data;
    }
}
