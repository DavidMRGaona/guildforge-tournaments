<?php

declare(strict_types=1);

namespace Modules\Tournaments\Filament\RelationManagers;

use App\Infrastructure\Persistence\Eloquent\Models\EventModel;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Fieldset;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Illuminate\Database\Eloquent\Model;
use Modules\Tournaments\Domain\Enums\Tiebreaker;
use Modules\Tournaments\Domain\Enums\TournamentStatus;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\TournamentModel;

/**
 * @property Form $configForm
 */
final class TournamentConfigRelationManager extends RelationManager
{
    protected static string $relationship = 'tournament';

    protected static ?string $recordTitleAttribute = 'name';

    protected static string $view = 'tournaments::filament.relation-managers.tournament-config-form';

    /** @var array<string, mixed> */
    public array $configData = [];

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('tournaments::messages.config.title');
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return $ownerRecord instanceof EventModel;
    }

    public function mount(): void
    {
        parent::mount();

        $eventId = $this->getOwnerRecord()->getKey();
        $tournament = TournamentModel::where('event_id', $eventId)->first();

        if ($tournament) {
            $this->configForm->fill([
                'tournament_enabled' => true,
                'name' => $tournament->name,
                'description' => $tournament->description,
                'max_rounds' => $tournament->max_rounds,
                'max_participants' => $tournament->max_participants,
                'min_participants' => $tournament->min_participants,
                'score_weights' => $tournament->score_weights,
                'tiebreakers' => $tournament->tiebreakers,
                'allow_guests' => $tournament->allow_guests,
                'requires_check_in' => $tournament->requires_check_in,
                'check_in_starts_before' => $tournament->check_in_starts_before,
                'result_reporting' => $tournament->result_reporting->value,
                'registration_opens_at' => $tournament->registration_opens_at?->format('Y-m-d H:i:s'),
                'registration_closes_at' => $tournament->registration_closes_at?->format('Y-m-d H:i:s'),
            ]);
        } else {
            $event = $this->getOwnerRecord();
            $this->configForm->fill([
                'tournament_enabled' => false,
                'name' => $event->title ?? '',
                'score_weights' => $this->getDefaultScoreWeights(),
                'tiebreakers' => ['buchholz', 'progressive'],
                'min_participants' => 2,
                'allow_guests' => false,
                'requires_check_in' => false,
                'result_reporting' => 'admin_only',
            ]);
        }
    }

    /**
     * @return array<string, Form>
     */
    protected function getForms(): array
    {
        return [
            'configForm' => $this->makeForm()
                ->schema($this->getFormSchema())
                ->statePath('configData'),
        ];
    }

    /**
     * @return array<\Filament\Forms\Components\Component>
     */
    protected function getFormSchema(): array
    {
        return [
            Section::make(__('tournaments::messages.config.general'))
                ->schema([
                    Toggle::make('tournament_enabled')
                        ->label(__('tournaments::messages.config.tournament_enabled'))
                        ->helperText(__('tournaments::messages.config.tournament_enabled_help'))
                        ->live()
                        ->columnSpanFull(),
                    TextInput::make('name')
                        ->label(__('tournaments::messages.config.name'))
                        ->required()
                        ->maxLength(255)
                        ->visible(fn (Get $get): bool => (bool) $get('tournament_enabled')),
                    TextInput::make('description')
                        ->label(__('tournaments::messages.config.description'))
                        ->maxLength(1000)
                        ->visible(fn (Get $get): bool => (bool) $get('tournament_enabled')),
                ]),

            Fieldset::make(__('tournaments::messages.config.capacity'))
                ->schema([
                    TextInput::make('max_participants')
                        ->label(__('tournaments::messages.config.max_participants'))
                        ->helperText(__('tournaments::messages.config.max_participants_help'))
                        ->numeric()
                        ->minValue(2)
                        ->nullable(),
                    TextInput::make('min_participants')
                        ->label(__('tournaments::messages.config.min_participants'))
                        ->numeric()
                        ->minValue(2)
                        ->default(2)
                        ->required(),
                    TextInput::make('max_rounds')
                        ->label(__('tournaments::messages.config.max_rounds'))
                        ->helperText(__('tournaments::messages.config.max_rounds_help'))
                        ->numeric()
                        ->minValue(1)
                        ->nullable(),
                ])
                ->columns(3)
                ->visible(fn (Get $get): bool => (bool) $get('tournament_enabled')),

            Fieldset::make(__('tournaments::messages.config.dates'))
                ->schema([
                    DateTimePicker::make('registration_opens_at')
                        ->label(__('tournaments::messages.config.registration_opens_at'))
                        ->native(false)
                        ->displayFormat('d/m/Y H:i')
                        ->nullable(),
                    DateTimePicker::make('registration_closes_at')
                        ->label(__('tournaments::messages.config.registration_closes_at'))
                        ->native(false)
                        ->displayFormat('d/m/Y H:i')
                        ->after('registration_opens_at')
                        ->nullable(),
                ])
                ->columns(2)
                ->visible(fn (Get $get): bool => (bool) $get('tournament_enabled')),

            Section::make(__('tournaments::messages.config.scoring'))
                ->schema([
                    Repeater::make('score_weights')
                        ->label(__('tournaments::messages.config.score_weights'))
                        ->schema([
                            TextInput::make('name')
                                ->label(__('tournaments::messages.config.score_weight_name'))
                                ->required(),
                            TextInput::make('key')
                                ->label(__('tournaments::messages.config.score_weight_key'))
                                ->required()
                                ->regex('/^[a-z_]+$/'),
                            TextInput::make('points')
                                ->label(__('tournaments::messages.config.score_weight_points'))
                                ->numeric()
                                ->required()
                                ->step(0.5),
                        ])
                        ->columns(3)
                        ->defaultItems(4)
                        ->reorderable(false)
                        ->addActionLabel(__('tournaments::messages.config.add_score_weight')),
                    Select::make('tiebreakers')
                        ->label(__('tournaments::messages.config.tiebreakers'))
                        ->helperText(__('tournaments::messages.config.tiebreakers_help'))
                        ->multiple()
                        ->options(Tiebreaker::options())
                        ->default(['buchholz', 'progressive']),
                ])
                ->visible(fn (Get $get): bool => (bool) $get('tournament_enabled')),

            Fieldset::make(__('tournaments::messages.config.options'))
                ->schema([
                    Select::make('result_reporting')
                        ->label(__('tournaments::messages.config.result_reporting'))
                        ->helperText(__('tournaments::messages.config.result_reporting_help'))
                        ->options([
                            'admin_only' => __('tournaments::messages.result_reporting.admin_only'),
                            'players_with_confirmation' => __('tournaments::messages.result_reporting.players_with_confirmation'),
                            'players_trusted' => __('tournaments::messages.result_reporting.players_trusted'),
                        ])
                        ->default('admin_only')
                        ->required(),
                    Toggle::make('allow_guests')
                        ->label(__('tournaments::messages.config.allow_guests'))
                        ->helperText(__('tournaments::messages.config.allow_guests_help')),
                    Toggle::make('requires_check_in')
                        ->label(__('tournaments::messages.config.requires_check_in'))
                        ->live(),
                    TextInput::make('check_in_starts_before')
                        ->label(__('tournaments::messages.config.check_in_starts_before'))
                        ->helperText(__('tournaments::messages.config.check_in_starts_before_help'))
                        ->numeric()
                        ->suffix(__('tournaments::messages.minutes'))
                        ->default(30)
                        ->visible(fn (Get $get): bool => (bool) $get('requires_check_in')),
                ])
                ->columns(2)
                ->visible(fn (Get $get): bool => (bool) $get('tournament_enabled')),
        ];
    }

    public function save(): void
    {
        $formData = $this->configForm->getState();
        $eventId = $this->getOwnerRecord()->getKey();

        if (! $formData['tournament_enabled']) {
            // Delete tournament if it exists and is disabled
            TournamentModel::where('event_id', $eventId)->delete();

            Notification::make()
                ->title(__('tournaments::messages.config.tournament_disabled'))
                ->success()
                ->send();

            return;
        }

        $tournament = TournamentModel::where('event_id', $eventId)->first();

        $data = [
            'event_id' => $eventId,
            'name' => $formData['name'],
            'description' => $formData['description'] ?? null,
            'max_rounds' => $formData['max_rounds'] ?? null,
            'max_participants' => $formData['max_participants'] ?? null,
            'min_participants' => $formData['min_participants'] ?? 2,
            'score_weights' => $formData['score_weights'] ?? $this->getDefaultScoreWeights(),
            'tiebreakers' => $formData['tiebreakers'] ?? ['buchholz', 'progressive'],
            'allow_guests' => $formData['allow_guests'] ?? false,
            'requires_check_in' => $formData['requires_check_in'] ?? false,
            'check_in_starts_before' => $formData['check_in_starts_before'] ?? null,
            'result_reporting' => $formData['result_reporting'] ?? 'admin_only',
            'registration_opens_at' => $formData['registration_opens_at'] ?? null,
            'registration_closes_at' => $formData['registration_closes_at'] ?? null,
        ];

        if ($tournament) {
            $tournament->update($data);
        } else {
            $data['status'] = TournamentStatus::Draft->value;
            TournamentModel::create($data);
        }

        Notification::make()
            ->title(__('tournaments::messages.config.saved'))
            ->success()
            ->send();
    }

    /**
     * @return array<array{name: string, key: string, points: float}>
     */
    private function getDefaultScoreWeights(): array
    {
        return [
            ['name' => 'Victoria', 'key' => 'win', 'points' => 3],
            ['name' => 'Empate', 'key' => 'draw', 'points' => 1],
            ['name' => 'Derrota', 'key' => 'loss', 'points' => 0],
            ['name' => 'Bye', 'key' => 'bye', 'points' => 3],
        ];
    }
}
