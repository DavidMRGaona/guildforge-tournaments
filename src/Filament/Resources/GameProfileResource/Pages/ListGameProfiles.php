<?php

declare(strict_types=1);

namespace Modules\Tournaments\Filament\Resources\GameProfileResource\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Modules\Tournaments\Filament\Resources\GameProfileResource;

final class ListGameProfiles extends ListRecords
{
    protected static string $resource = GameProfileResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
