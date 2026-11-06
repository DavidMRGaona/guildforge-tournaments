<?php

declare(strict_types=1);

namespace Modules\Tournaments\Filament\Resources\TournamentResource\Pages;

use App\Filament\Concerns\HasKnowledgeGuideAction;
use Filament\Actions\Action;
use Filament\Resources\Pages\CreateRecord;
use Modules\Tournaments\Domain\Enums\TournamentStatus;
use Modules\Tournaments\Filament\Resources\TournamentResource;

final class CreateTournament extends CreateRecord
{
    use HasKnowledgeGuideAction;

    protected static string $resource = TournamentResource::class;

    public function getTitle(): string
    {
        return __('tournaments::messages.resource.actions.create');
    }

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return array_filter([
            $this->knowledgeGuideAction('tournaments.panel.create-tournament'),
        ]);
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
