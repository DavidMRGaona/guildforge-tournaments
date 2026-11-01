<?php

declare(strict_types=1);

namespace Modules\Tournaments\Filament\Resources\TournamentResource\RelationManagers;

use App\Application\Services\TimezoneServiceInterface;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Modules\Tournaments\Application\DTOs\RecordMatchResultDTO;
use Modules\Tournaments\Application\DTOs\Response\MatchHistoryResponseDTO;
use Modules\Tournaments\Application\Services\MatchQueryServiceInterface;
use Modules\Tournaments\Application\Services\MatchReportingServiceInterface;
use Modules\Tournaments\Application\Services\MatchResultServiceInterface;
use Modules\Tournaments\Domain\Enums\MatchResult;
use Modules\Tournaments\Domain\Exceptions\InvalidMatchResultException;
use Modules\Tournaments\Domain\Exceptions\MatchReportClosedException;
use Modules\Tournaments\Domain\Exceptions\MatchResultLockedException;
use Modules\Tournaments\Domain\Exceptions\ResultNotConfirmedException;
use Modules\Tournaments\Domain\Repositories\MatchReportRepositoryInterface;
use Modules\Tournaments\Filament\InvalidMatchResultMessage;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\MatchModel;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\MatchReportModel;
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
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('report'))
            ->columns([
                TextColumn::make('table_number')
                    ->label(__('tournaments::messages.fields.table_number'))
                    ->sortable()
                    ->default('-'),

                TextColumn::make('player1.participant_name')
                    ->label(__('tournaments::messages.fields.player_1'))
                    ->getStateUsing(fn (MatchModel $record): string => $record->player1->participant_name),

                TextColumn::make('player2.participant_name')
                    ->label(__('tournaments::messages.fields.player_2'))
                    ->getStateUsing(function (MatchModel $record): string {
                        if ($record->player_2_id === null) {
                            return __('tournaments::messages.fields.bye_opponent');
                        }

                        return $record->player2->participant_name ?? __('tournaments::messages.participants.unknown');
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

                // A players' report waiting for confirmation; the reason of a dispute on hover
                TextColumn::make('report_status')
                    ->label(__('tournaments::messages.fields.report_status'))
                    ->badge()
                    ->getStateUsing(fn (MatchModel $record): ?string => match (true) {
                        ! $record->report instanceof MatchReportModel, $record->result !== MatchResult::NotPlayed => null,
                        $record->report->disputed_at !== null => (string) __('tournaments::messages.report_status.disputed'),
                        default => (string) __('tournaments::messages.report_status.pending'),
                    })
                    ->color(fn (MatchModel $record): string => $record->report?->disputed_at !== null ? 'danger' : 'warning')
                    ->tooltip(fn (MatchModel $record): ?string => $record->report?->dispute_reason),
            ])
            ->recordActions([
                Action::make('reportResult')
                    ->label(__('tournaments::messages.actions.report_result'))
                    ->icon('heroicon-o-pencil-square')
                    ->color('primary')
                    ->visible(fn (MatchModel $record): bool => $record->result === MatchResult::NotPlayed)
                    ->authorize(fn (): bool => $this->canReportResults())
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
                                ->heading($record->player1->participant_name)
                                ->schema($player1Fields)
                                ->columns(2);

                            // Only add Player 2 section if it's not a bye
                            if ($record->player_2_id !== null) {
                                $player2Fields = [];

                                foreach ($statDefinitions as $stat) {
                                    $player2Fields[] = $this->createStatField($stat, 'player_2_stats');
                                }

                                $fields[] = Section::make('player_2_section')
                                    ->heading($record->player2->participant_name ?? __('tournaments::messages.fields.player_2'))
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

                Action::make('confirmReport')
                    ->label(__('tournaments::messages.actions.confirm_report'))
                    ->icon('heroicon-o-check-badge')
                    ->color('success')
                    ->visible(fn (MatchModel $record): bool => $record->report instanceof MatchReportModel && $record->result === MatchResult::NotPlayed)
                    ->authorize(fn (): bool => $this->canReportResults())
                    ->requiresConfirmation()
                    ->modalDescription(fn (MatchModel $record): string => $this->reportSummary($record))
                    // What the modal shows: a correction made while it is open is not confirmed blindly
                    ->fillForm(fn (MatchModel $record, MatchReportRepositoryInterface $reports): array => [
                        'report' => $reports->find($record->id)?->fingerprint(),
                    ])
                    ->schema([Hidden::make('report')])
                    ->action(function (MatchModel $record, array $data, MatchReportingServiceInterface $reporting): void {
                        try {
                            $reporting->confirmAsOrganizer($record->id, (string) auth()->id(), (string) ($data['report'] ?? ''));
                        } catch (MatchReportClosedException) {
                            Notification::make()
                                ->title(__('tournaments::messages.errors.no_pending_report'))
                                ->danger()
                                ->send();

                            return;
                        } catch (ResultNotConfirmedException $exception) {
                            Notification::make()
                                ->title($exception->reportWasChanged()
                                    ? __('tournaments::messages.errors.report_changed')
                                    : __('tournaments::messages.errors.no_pending_report'))
                                ->danger()
                                ->send();

                            return;
                        }

                        Notification::make()
                            ->title(__('tournaments::messages.messages.report_confirmed'))
                            ->success()
                            ->send();
                    }),

                // Not irreversible: the result can be recorded again and the history keeps both
                Action::make('resetResult')
                    ->label(__('tournaments::messages.actions.reset_result'))
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->color('danger')
                    ->visible(fn (MatchModel $record): bool => $record->player_2_id !== null
                        && ($record->result !== MatchResult::NotPlayed || $record->report instanceof MatchReportModel))
                    ->authorize(fn (): bool => $this->canReportResults())
                    ->requiresConfirmation()
                    ->modalDescription(__('tournaments::messages.modal.reset_result'))
                    ->schema([
                        Textarea::make('reason')
                            ->label(__('tournaments::messages.fields.reset_reason'))
                            ->required()
                            ->maxLength(500),
                    ])
                    ->action(function (MatchModel $record, array $data, MatchResultServiceInterface $results): void {
                        try {
                            $results->resetResult($record->id, (string) auth()->id(), (string) $data['reason']);
                        } catch (MatchResultLockedException) {
                            Notification::make()
                                ->title(__('tournaments::messages.errors.result_locked_by_next_round'))
                                ->danger()
                                ->send();

                            return;
                        }

                        Notification::make()
                            ->title(__('tournaments::messages.messages.result_reset'))
                            ->success()
                            ->send();
                    }),

                Action::make('history')
                    ->label(__('tournaments::messages.actions.history'))
                    ->icon('heroicon-o-clock')
                    ->color('gray')
                    ->visible(fn (MatchModel $record): bool => $record->history()->exists())
                    ->authorize(fn (): bool => $this->canReportResults())
                    ->modalSubmitAction(false)
                    ->schema(fn (MatchModel $record, MatchQueryServiceInterface $queries): array => array_map(
                        fn (MatchHistoryResponseDTO $entry): TextEntry => TextEntry::make('history_'.$entry->id)
                            ->label(implode(' · ', [
                                app(TimezoneServiceInterface::class)->format($entry->changedAt),
                                $entry->action->label(),
                                $entry->changedByName,
                            ]))
                            ->state($this->historySummary($entry)),
                        $queries->getHistory($record->id),
                    )),
            ])
            ->defaultSort('table_number', 'asc');
    }

    /**
     * What a player reported, for the organization to confirm.
     */
    private function reportSummary(MatchModel $match): string
    {
        $report = $match->report;
        if (! $report instanceof MatchReportModel) {
            return '';
        }

        $summary = __('tournaments::messages.modal.confirm_report', [
            'result' => $report->result->label(),
            'name' => $report->reportedBy->name,
        ]);

        if ($report->player_1_score !== null && $report->player_2_score !== null) {
            $summary .= ' ('.$report->player_1_score.'-'.$report->player_2_score.')';
        }

        return $report->dispute_reason !== null
            ? $summary.'. '.__('tournaments::messages.modal.disputed_because', ['reason' => $report->dispute_reason])
            : $summary;
    }

    private function historySummary(MatchHistoryResponseDTO $entry): string
    {
        $summary = __('tournaments::messages.history.change', [
            'from' => $entry->previousResult?->label() ?? MatchResult::NotPlayed->label(),
            'to' => $entry->newResult->label(),
        ]);

        if ($entry->newPlayer1Score !== null && $entry->newPlayer2Score !== null) {
            $summary .= ' ('.$entry->newPlayer1Score.'-'.$entry->newPlayer2Score.')';
        }

        return $entry->reason !== null
            ? $summary.'. '.__('tournaments::messages.history.reason', ['reason' => $entry->reason])
            : $summary;
    }

    /**
     * Recording results needs tournaments.report_results, not only editing the tournament.
     */
    private function canReportResults(): bool
    {
        /** @var RoundModel $round */
        $round = $this->getOwnerRecord();

        return auth()->user()?->can('reportResults', $round->tournament) ?? false;
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
     *
     * @param  array{key: string, name: string, type?: string, min_value?: int|float|null, max_value?: int|float|null, required?: bool}  $stat
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
