<?php

declare(strict_types=1);

namespace Modules\Tournaments\Filament\Resources\TournamentResource\RelationManagers;

use Filament\Forms\Components\Component;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Enums\MaxWidth;
use Filament\Tables\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Modules\Tournaments\Domain\Enums\MatchResult;
use Modules\Tournaments\Domain\Enums\RoundStatus;
use Modules\Tournaments\Domain\Enums\TournamentStatus;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\MatchModel;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\RoundModel;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\TournamentModel;

final class RoundsRelationManager extends RelationManager
{
    protected static string $relationship = 'rounds';

    protected static ?string $recordTitleAttribute = 'round_number';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('tournaments::messages.resource.rounds_title');
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('round_number')
                    ->label(__('tournaments::messages.resource.fields.round_number'))
                    ->formatStateUsing(fn (int $state): string => __('tournaments::messages.table.round_number', ['number' => $state]))
                    ->sortable(),

                TextColumn::make('status')
                    ->label(__('tournaments::messages.fields.status'))
                    ->badge()
                    ->color(fn (RoundStatus $state): string => $state->color())
                    ->formatStateUsing(fn (RoundStatus $state): string => $state->label())
                    ->sortable(),

                TextColumn::make('matches_count')
                    ->label(__('tournaments::messages.resource.fields.matches_count'))
                    ->counts('matches')
                    ->sortable(),

                TextColumn::make('completed_matches')
                    ->label(__('tournaments::messages.resource.fields.completed_matches'))
                    ->getStateUsing(function (RoundModel $record): string {
                        $total = $record->matches()->count();
                        $completed = $record->matches()
                            ->where('result', '!=', 'not_played')
                            ->count();

                        return "{$completed}/{$total}";
                    }),

                TextColumn::make('started_at')
                    ->label(__('tournaments::messages.resource.fields.started_at'))
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('completed_at')
                    ->label(__('tournaments::messages.resource.fields.completed_at'))
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('tournaments::messages.fields.status'))
                    ->options(RoundStatus::options()),
            ])
            ->headerActions([
                Action::make('generateRound')
                    ->label(__('tournaments::messages.actions.generate_round'))
                    ->icon('heroicon-o-sparkles')
                    ->color('primary')
                    ->visible(function (): bool {
                        /** @var TournamentModel $tournament */
                        $tournament = $this->getOwnerRecord();

                        return $tournament->status === TournamentStatus::InProgress;
                    })
                    ->requiresConfirmation()
                    ->action(function (): void {
                        /** @var TournamentModel $tournament */
                        $tournament = $this->getOwnerRecord();

                        // Check if previous round is completed
                        $lastRound = $tournament->rounds()->orderBy('round_number', 'desc')->first();
                        if ($lastRound !== null && $lastRound->status !== RoundStatus::Finished) {
                            Notification::make()
                                ->title(__('tournaments::messages.errors.previous_round_not_completed'))
                                ->danger()
                                ->send();

                            return;
                        }

                        $nextRoundNumber = ($lastRound?->round_number ?? 0) + 1;

                        // Create new round (pairings would be generated by the service in a real implementation)
                        RoundModel::create([
                            'tournament_id' => $tournament->id,
                            'round_number' => $nextRoundNumber,
                            'status' => RoundStatus::Pending->value,
                        ]);

                        $tournament->update(['current_round' => $nextRoundNumber]);

                        Notification::make()
                            ->title(__('tournaments::messages.messages.round_generated', ['number' => $nextRoundNumber]))
                            ->success()
                            ->send();
                    }),
            ])
            ->actions([
                Action::make('manageMatches')
                    ->label(__('tournaments::messages.actions.manage_matches'))
                    ->icon('heroicon-o-table-cells')
                    ->color('gray')
                    ->modalWidth(MaxWidth::SevenExtraLarge)
                    ->slideOver()
                    ->fillForm(function (RoundModel $record): array {
                        $matches = $record->matches()->with(['player1', 'player2'])->orderBy('table_number')->get();
                        $matchesData = [];

                        foreach ($matches as $match) {
                            /** @var MatchModel $match */
                            $matchesData[] = [
                                'match_id' => $match->id,
                                'table_number' => $match->table_number,
                                'player1_name' => $match->player1?->participant_name ?? 'Desconocido',
                                'player2_name' => $match->player_2_id === null ? 'Bye' : ($match->player2?->participant_name ?? 'Desconocido'),
                                'result' => $match->result->value,
                                'player_1_stats' => $match->player_1_stats ?? [],
                                'player_2_stats' => $match->player_2_stats ?? [],
                                'is_bye' => $match->player_2_id === null,
                            ];
                        }

                        return ['matches' => $matchesData];
                    })
                    ->form(fn (RoundModel $record): array => [
                        Repeater::make('matches')
                            ->label(__('tournaments::messages.resource.fields.matches_count'))
                            ->schema(function () use ($record): array {
                                $tournament = $record->tournament;

                                return $this->getMatchFormSchema($tournament);
                            })
                            ->addable(false)
                            ->deletable(false)
                            ->reorderable(false)
                            ->collapsible()
                            ->itemLabel(fn (array $state): ?string => isset($state['table_number'])
                                ? __('tournaments::messages.fields.table_number').' '.$state['table_number'].' - '.$state['player1_name'].' vs '.$state['player2_name']
                                : null)
                            ->columnSpanFull(),
                    ])
                    ->action(function (RoundModel $record, array $data): void {
                        foreach ($data['matches'] as $matchData) {
                            $match = MatchModel::find($matchData['match_id']);
                            if ($match === null) {
                                continue;
                            }

                            // Only update if result has changed
                            if ($match->result->value !== $matchData['result']) {
                                $match->update([
                                    'result' => $matchData['result'],
                                    'player_1_stats' => $matchData['player_1_stats'] ?? null,
                                    'player_2_stats' => $matchData['player_2_stats'] ?? null,
                                    'reported_by_id' => auth()->id(),
                                    'reported_at' => now(),
                                ]);
                            }
                        }

                        Notification::make()
                            ->title(__('tournaments::messages.messages.results_updated'))
                            ->success()
                            ->send();
                    }),

                Action::make('startRound')
                    ->label(__('tournaments::messages.actions.start_round'))
                    ->icon('heroicon-o-play')
                    ->color('success')
                    ->visible(fn (RoundModel $record): bool => $record->status === RoundStatus::Pending)
                    ->requiresConfirmation()
                    ->action(function (RoundModel $record): void {
                        $record->update([
                            'status' => RoundStatus::InProgress->value,
                            'started_at' => now(),
                        ]);

                        Notification::make()
                            ->title(__('tournaments::messages.messages.round_started'))
                            ->success()
                            ->send();
                    }),

                Action::make('completeRound')
                    ->label(__('tournaments::messages.actions.complete_round'))
                    ->icon('heroicon-o-check-circle')
                    ->color('primary')
                    ->visible(fn (RoundModel $record): bool => $record->status === RoundStatus::InProgress)
                    ->requiresConfirmation()
                    ->action(function (RoundModel $record): void {
                        // Check if all matches have results
                        $unplayedMatches = $record->matches()
                            ->where('result', 'not_played')
                            ->count();

                        if ($unplayedMatches > 0) {
                            Notification::make()
                                ->title(__('tournaments::messages.errors.matches_not_completed'))
                                ->body(__('tournaments::messages.errors.matches_not_completed_detail', ['count' => $unplayedMatches]))
                                ->danger()
                                ->send();

                            return;
                        }

                        $record->update([
                            'status' => RoundStatus::Finished->value,
                            'completed_at' => now(),
                        ]);

                        Notification::make()
                            ->title(__('tournaments::messages.messages.round_completed'))
                            ->success()
                            ->send();
                    }),
            ])
            ->defaultSort('round_number', 'asc');
    }

    /**
     * Get the match form schema with dynamic stat fields.
     *
     * @return array<Component>
     */
    private function getMatchFormSchema(TournamentModel $tournament): array
    {
        $statDefinitions = $tournament->stat_definitions ?? [];

        $schema = [
            TextInput::make('match_id')
                ->hidden()
                ->dehydrated(),

            TextInput::make('table_number')
                ->label(__('tournaments::messages.fields.table_number'))
                ->disabled(),

            TextInput::make('player1_name')
                ->label('Jugador 1')
                ->disabled(),

            TextInput::make('player2_name')
                ->label('Jugador 2')
                ->disabled(),

            Select::make('result')
                ->label(__('tournaments::messages.fields.status'))
                ->options(fn (Get $get): array => $get('is_bye')
                    ? [MatchResult::Bye->value => MatchResult::Bye->label()]
                    : MatchResult::options())
                ->required()
                ->native(false)
                ->live(),

            Toggle::make('is_bye')
                ->hidden()
                ->dehydrated(),
        ];

        if (count($statDefinitions) > 0) {
            $player1StatFields = [];
            $player2StatFields = [];

            foreach ($statDefinitions as $stat) {
                $player1StatFields[] = $this->createStatField($stat, 'player_1_stats');
                $player2StatFields[] = $this->createStatField($stat, 'player_2_stats');
            }

            $schema[] = Section::make('player_1_stats_section')
                ->heading('Estadísticas jugador 1')
                ->schema($player1StatFields)
                ->columns(2)
                ->visible(fn (Get $get): bool => $get('result') !== MatchResult::NotPlayed->value && $get('result') !== null);

            $schema[] = Section::make('player_2_stats_section')
                ->heading('Estadísticas jugador 2')
                ->schema($player2StatFields)
                ->columns(2)
                ->visible(fn (Get $get): bool => ! $get('is_bye') && $get('result') !== MatchResult::NotPlayed->value && $get('result') !== null);
        }

        return $schema;
    }

    /**
     * Create a dynamic stat field based on stat definition.
     */
    private function createStatField(array $stat, string $prefix): Component
    {
        $fieldName = "{$prefix}.{$stat['key']}";
        $label = $stat['name'];
        $type = $stat['type'] ?? 'integer';
        $required = $stat['required'] ?? false;

        return match ($type) {
            'integer' => TextInput::make($fieldName)
                ->label($label)
                ->numeric()
                ->minValue($stat['min_value'] ?? null)
                ->maxValue($stat['max_value'] ?? null)
                ->required($required),
            'float' => TextInput::make($fieldName)
                ->label($label)
                ->numeric()
                ->step(0.01)
                ->minValue($stat['min_value'] ?? null)
                ->maxValue($stat['max_value'] ?? null)
                ->required($required),
            'boolean' => Toggle::make($fieldName)
                ->label($label)
                ->required($required),
            default => TextInput::make($fieldName)
                ->label($label)
                ->numeric()
                ->required($required),
        };
    }
}
