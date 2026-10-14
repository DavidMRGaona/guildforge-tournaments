<?php

declare(strict_types=1);

namespace Modules\Tournaments\Filament\Resources\TournamentResource\RelationManagers;

use DomainException;
use Filament\Actions\Action;
use Filament\Support\Enums\Width;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Component;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Modules\Tournaments\Application\DTOs\RecordMatchResultDTO;
use Modules\Tournaments\Application\Services\MatchResultServiceInterface;
use Modules\Tournaments\Application\Services\RoundManagementServiceInterface;
use Modules\Tournaments\Domain\Enums\MatchResult;
use Modules\Tournaments\Domain\Enums\RoundStatus;
use Modules\Tournaments\Domain\Enums\TournamentStatus;
use Modules\Tournaments\Domain\Exceptions\CannotGeneratePairingsException;
use Modules\Tournaments\Domain\Exceptions\InsufficientParticipantsException;
use Modules\Tournaments\Domain\Exceptions\InvalidMatchResultException;
use Modules\Tournaments\Domain\Exceptions\MatchResultLockedException;
use Modules\Tournaments\Domain\Exceptions\PreviousRoundNotCompletedException;
use Modules\Tournaments\Domain\Exceptions\RoundAlreadyGeneratedException;
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
                    ->action(function (RoundManagementServiceInterface $rounds): void {
                        /** @var TournamentModel $tournament */
                        $tournament = $this->getOwnerRecord();

                        try {
                            $round = $rounds->generateNextRound($tournament->id);
                        } catch (DomainException $exception) {
                            Notification::make()
                                ->title(self::generationErrorMessage($exception))
                                ->danger()
                                ->send();

                            return;
                        }

                        Notification::make()
                            ->title(__('tournaments::messages.messages.round_generated', ['number' => $round->roundNumber]))
                            ->success()
                            ->send();
                    }),
            ])
            ->recordActions([
                Action::make('manageMatches')
                    ->label(__('tournaments::messages.actions.manage_matches'))
                    ->icon('heroicon-o-table-cells')
                    ->color('gray')
                    ->modalWidth(Width::SevenExtraLarge)
                    ->slideOver()
                    ->fillForm(function (RoundModel $record): array {
                        $matches = $record->matches()->with(['player1', 'player2'])->orderBy('table_number')->get();
                        $matchesData = [];

                        foreach ($matches as $match) {
                            /** @var MatchModel $match */
                            $matchesData[] = [
                                'match_id' => $match->id,
                                'table_number' => $match->table_number,
                                'player1_name' => $match->player1?->participant_name ?? __('tournaments::messages.participants.unknown'),
                                'player2_name' => $match->player_2_id === null ? __('tournaments::messages.fields.bye_opponent') : ($match->player2?->participant_name ?? __('tournaments::messages.participants.unknown')),
                                'result' => $match->result->value,
                                'player_1_score' => $match->player_1_score,
                                'player_2_score' => $match->player_2_score,
                                'player_1_stats' => $match->player_1_stats ?? [],
                                'player_2_stats' => $match->player_2_stats ?? [],
                                'is_bye' => $match->player_2_id === null,
                            ];
                        }

                        return ['matches' => $matchesData];
                    })
                    ->schema(fn (RoundModel $record): array => [
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
                    ->action(function (RoundModel $record, array $data, MatchResultServiceInterface $results): void {
                        $changes = [];
                        foreach ($data['matches'] as $matchData) {
                            $match = $record->matches()->find($matchData['match_id']);
                            if (! $match instanceof MatchModel) {
                                continue;
                            }

                            $player1Stats = $matchData['player_1_stats'] ?? null;
                            $player2Stats = $matchData['player_2_stats'] ?? null;
                            $player1Score = self::score($matchData['player_1_score'] ?? null);
                            $player2Score = self::score($matchData['player_2_score'] ?? null);

                            // Only record what changed
                            if ($match->result->value === $matchData['result']
                                && $match->player_1_score === $player1Score
                                && $match->player_2_score === $player2Score
                                && $match->player_1_stats == ($player1Stats ?: null)
                                && $match->player_2_stats == ($player2Stats ?: null)) {
                                continue;
                            }

                            $changes[] = new RecordMatchResultDTO(
                                matchId: $match->id,
                                result: MatchResult::from($matchData['result']),
                                reportedById: (string) auth()->id(),
                                player1Stats: $player1Stats ?: null,
                                player2Stats: $player2Stats ?: null,
                                player1Score: $player1Score,
                                player2Score: $player2Score,
                            );
                        }

                        // All or nothing, with one standings recalculation
                        try {
                            $results->recordResults($changes);
                        } catch (InvalidMatchResultException|MatchResultLockedException $exception) {
                            Notification::make()
                                ->title($exception instanceof MatchResultLockedException
                                    ? __('tournaments::messages.errors.result_locked_by_next_round')
                                    : __('tournaments::messages.errors.invalid_match_result'))
                                ->danger()
                                ->send();

                            return;
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
     * Results of a match between two players; "not played" leaves (or puts back) a match without result.
     *
     * @return array<string, string>
     */
    private static function playerMatchResultOptions(): array
    {
        $options = [MatchResult::NotPlayed->value => MatchResult::NotPlayed->label()];
        foreach (MatchResult::options() as $value => $label) {
            if ($value !== MatchResult::Bye->value) {
                $options[$value] = $label;
            }
        }

        return $options;
    }

    private static function score(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    private static function generationErrorMessage(DomainException $exception): string
    {
        return match (true) {
            $exception instanceof RoundAlreadyGeneratedException => __('tournaments::messages.errors.round_already_generated'),
            $exception instanceof PreviousRoundNotCompletedException => __('tournaments::messages.errors.previous_round_not_completed'),
            $exception instanceof InsufficientParticipantsException => __('tournaments::messages.errors.not_enough_players_for_round'),
            $exception instanceof CannotGeneratePairingsException => __('tournaments::messages.errors.cannot_generate_pairings'),
            default => throw $exception,
        };
    }

    /**
     * Get the match form schema with dynamic stat fields.
     *
     * @return array<\Filament\Schemas\Components\Component>
     */
    private function getMatchFormSchema(TournamentModel $tournament): array
    {
        $statDefinitions = $tournament->stat_definitions ?? [];

        $schema = [
            // A hidden() field is left out of the submitted state; Hidden is kept
            Hidden::make('match_id'),

            TextInput::make('table_number')
                ->label(__('tournaments::messages.fields.table_number'))
                ->disabled(),

            TextInput::make('player1_name')
                ->label(__('tournaments::messages.fields.player_1'))
                ->disabled(),

            TextInput::make('player2_name')
                ->label(__('tournaments::messages.fields.player_2'))
                ->disabled(),

            Select::make('result')
                ->label(__('tournaments::messages.fields.status'))
                ->options(fn (Get $get): array => $get('is_bye')
                    ? [MatchResult::Bye->value => MatchResult::Bye->label()]
                    : self::playerMatchResultOptions())
                ->required()
                ->native(false)
                ->live(),

            Hidden::make('is_bye'),

            TextInput::make('player_1_score')
                ->label(__('tournaments::messages.fields.player_1_score'))
                ->helperText(__('tournaments::messages.fields.score_help'))
                ->integer()
                ->minValue(0)
                ->nullable()
                ->visible(fn (Get $get): bool => ! $get('is_bye')),

            TextInput::make('player_2_score')
                ->label(__('tournaments::messages.fields.player_2_score'))
                ->integer()
                ->minValue(0)
                ->nullable()
                ->visible(fn (Get $get): bool => ! $get('is_bye')),
        ];

        if (count($statDefinitions) > 0) {
            $player1StatFields = [];
            $player2StatFields = [];

            foreach ($statDefinitions as $stat) {
                $player1StatFields[] = $this->createStatField($stat, 'player_1_stats');
                $player2StatFields[] = $this->createStatField($stat, 'player_2_stats');
            }

            $schema[] = Section::make('player_1_stats_section')
                ->heading(__('tournaments::messages.fields.player_1_stats'))
                ->schema($player1StatFields)
                ->columns(2)
                ->visible(fn (Get $get): bool => $get('result') !== MatchResult::NotPlayed->value && $get('result') !== null);

            $schema[] = Section::make('player_2_stats_section')
                ->heading(__('tournaments::messages.fields.player_2_stats'))
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
