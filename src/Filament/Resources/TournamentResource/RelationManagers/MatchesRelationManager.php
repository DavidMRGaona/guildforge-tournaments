<?php

declare(strict_types=1);

namespace Modules\Tournaments\Filament\Resources\TournamentResource\RelationManagers;

use Filament\Actions\Action;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Component;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Modules\Tournaments\Application\DTOs\RecordMatchResultDTO;
use Modules\Tournaments\Application\Services\MatchResultServiceInterface;
use Modules\Tournaments\Domain\Enums\MatchResult;
use Modules\Tournaments\Domain\Exceptions\InvalidMatchResultException;
use Modules\Tournaments\Domain\Exceptions\MatchResultLockedException;
use Modules\Tournaments\Filament\InvalidMatchResultMessage;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\MatchModel;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\RoundModel;

final class MatchesRelationManager extends RelationManager
{
    protected static string $relationship = 'matches';

    protected static ?string $recordTitleAttribute = 'table_number';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        /** @var RoundModel $ownerRecord */
        return __('tournaments::messages.table.round_number', ['number' => $ownerRecord->round_number]).' - '.__('tournaments::messages.resource.fields.matches_count');
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('table_number')
                    ->label(__('tournaments::messages.fields.table_number'))
                    ->sortable()
                    ->default('-'),

                TextColumn::make('player1.participant_name')
                    ->label(__('tournaments::messages.fields.player_1'))
                    ->getStateUsing(function (MatchModel $record): string {
                        return $record->player1?->participant_name ?? __('tournaments::messages.participants.unknown');
                    }),

                TextColumn::make('player2.participant_name')
                    ->label(__('tournaments::messages.fields.player_2'))
                    ->getStateUsing(function (MatchModel $record): string {
                        if ($record->player_2_id === null) {
                            return __('tournaments::messages.fields.bye_opponent');
                        }

                        return $record->player2?->participant_name ?? __('tournaments::messages.participants.unknown');
                    }),

                TextColumn::make('result')
                    ->label(__('tournaments::messages.fields.status'))
                    ->badge()
                    ->color(fn (MatchResult $state): string => $state->color())
                    ->formatStateUsing(fn (MatchResult $state): string => $state->label())
                    ->sortable(),

                TextColumn::make('player_1_score')
                    ->label(__('tournaments::messages.fields.player_1_score'))
                    ->default('-')
                    ->toggleable(),

                TextColumn::make('player_2_score')
                    ->label(__('tournaments::messages.fields.player_2_score'))
                    ->default('-')
                    ->toggleable(),
            ])
            ->recordActions([
                Action::make('reportResult')
                    ->label(__('tournaments::messages.actions.report_result'))
                    ->icon('heroicon-o-pencil-square')
                    ->color('primary')
                    ->visible(fn (MatchModel $record): bool => $record->result === MatchResult::NotPlayed)
                    ->schema(function (MatchModel $record): array {
                        $fields = [
                            Select::make('result')
                                ->label(__('tournaments::messages.fields.status'))
                                ->options($this->getResultOptions($record))
                                ->required()
                                ->native(false),
                        ];

                        if ($record->player_2_id !== null) {
                            $fields[] = TextInput::make('player_1_score')
                                ->label(__('tournaments::messages.fields.player_1_score'))
                                ->helperText(__('tournaments::messages.fields.score_help'))
                                ->integer()
                                ->minValue(0)
                                ->nullable();
                            $fields[] = TextInput::make('player_2_score')
                                ->label(__('tournaments::messages.fields.player_2_score'))
                                ->integer()
                                ->minValue(0)
                                ->nullable();
                        }

                        // Get tournament's stat_definitions through round relationship
                        $tournament = $record->round->tournament;
                        $statDefinitions = $tournament->stat_definitions ?? [];

                        if (count($statDefinitions) > 0) {
                            // Add section for Player 1 stats
                            $player1Fields = [];

                            foreach ($statDefinitions as $stat) {
                                $player1Fields[] = $this->createStatField($stat, 'player_1_stats');
                            }

                            $fields[] = Section::make('player_1_section')
                                ->heading($record->player1?->participant_name ?? __('tournaments::messages.fields.player_1'))
                                ->schema($player1Fields)
                                ->columns(2);

                            // Only add Player 2 section if it's not a bye
                            if ($record->player_2_id !== null) {
                                $player2Fields = [];

                                foreach ($statDefinitions as $stat) {
                                    $player2Fields[] = $this->createStatField($stat, 'player_2_stats');
                                }

                                $fields[] = Section::make('player_2_section')
                                    ->heading($record->player2?->participant_name ?? __('tournaments::messages.fields.player_2'))
                                    ->schema($player2Fields)
                                    ->columns(2);
                            }
                        }

                        return $fields;
                    })
                    ->action(function (MatchModel $record, array $data, MatchResultServiceInterface $results): void {
                        try {
                            $results->recordResult(new RecordMatchResultDTO(
                                matchId: $record->id,
                                result: MatchResult::from($data['result']),
                                reportedById: (string) auth()->id(),
                                player1Stats: ($data['player_1_stats'] ?? null) ?: null,
                                player2Stats: ($data['player_2_stats'] ?? null) ?: null,
                                player1Score: is_numeric($data['player_1_score'] ?? null) ? (int) $data['player_1_score'] : null,
                                player2Score: is_numeric($data['player_2_score'] ?? null) ? (int) $data['player_2_score'] : null,
                            ));
                        } catch (InvalidMatchResultException|MatchResultLockedException $exception) {
                            Notification::make()
                                ->title($exception instanceof MatchResultLockedException
                                    ? __('tournaments::messages.errors.result_locked_by_next_round')
                                    : InvalidMatchResultMessage::for($exception))
                                ->danger()
                                ->send();

                            return;
                        }

                        Notification::make()
                            ->title(__('tournaments::messages.messages.result_reported'))
                            ->success()
                            ->send();
                    }),
            ])
            ->defaultSort('table_number', 'asc');
    }

    /**
     * Get result options based on whether it's a bye match.
     *
     * @return array<string, string>
     */
    private function getResultOptions(MatchModel $match): array
    {
        // If it's a bye match, only show bye option
        if ($match->player_2_id === null) {
            return [
                MatchResult::Bye->value => MatchResult::Bye->label(),
            ];
        }

        // Otherwise every played result except the bye
        return array_diff_key(MatchResult::options(), [MatchResult::Bye->value => true]);
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
