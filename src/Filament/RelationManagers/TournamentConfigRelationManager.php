<?php

declare(strict_types=1);

namespace Modules\Tournaments\Filament\RelationManagers;

use App\Filament\Forms\Components\EndSurveyField;
use App\Infrastructure\Persistence\Eloquent\Models\EventModel;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;
use Modules\Tournaments\Domain\Enums\ConditionType;
use Modules\Tournaments\Domain\Enums\Tiebreaker;
use Modules\Tournaments\Domain\Enums\TiebreakerType;
use Modules\Tournaments\Domain\Enums\TournamentStatus;
use Modules\Tournaments\Domain\ValueObjects\ScoringRule;
use Modules\Tournaments\Domain\ValueObjects\TiebreakerDefinition;
use Modules\Tournaments\Filament\RegistrationClosingDateRule;
use Modules\Tournaments\Filament\TournamentFormDefaults;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\TournamentModel;
use Modules\Tournaments\Infrastructure\Surveys\TournamentSurveySubjectSource;

/**
 * @property Schema $configForm
 */
final class TournamentConfigRelationManager extends RelationManager
{
    protected static string $relationship = 'tournament';

    /** Tiebreakers of a tournament without own or profile tiebreakers, as the standings use them. */
    private const array DEFAULT_TIEBREAKERS = ['buchholz', 'progressive'];

    protected static ?string $recordTitleAttribute = 'name';

    // The module registers its view namespace at runtime, so Larastan cannot resolve it as a view-string
    protected string $view = 'tournaments::filament.relation-managers.tournament-config-form'; // @phpstan-ignore property.defaultValue

    /** @var array<string, mixed> */
    public array $configData = [];

    /**
     * The tournament has rules the tab cannot show (stat conditions, other tiebreaker types):
     * the tab leaves them alone and points to the tournament form.
     * Locked: a client clearing it would make the save overwrite those rules.
     */
    #[Locked]
    public bool $hasAdvancedRules = false;

    /**
     * The game profile whose points or tiebreakers the tab shows because the tournament has none of its own.
     */
    #[Locked]
    public ?string $inheritedProfileName = null;

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('tournaments::messages.config.title');
    }

    /**
     * Editing the event is not enough: the tab follows the tournaments permissions.
     */
    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return $ownerRecord instanceof EventModel && Gate::allows('viewAny', TournamentModel::class);
    }

    public function mount(): void
    {
        parent::mount();

        $eventId = $this->getOwnerRecord()->getKey();
        $tournament = TournamentModel::where('event_id', $eventId)->first();

        if ($tournament) {
            // The rules the standings use: the tournament's own, else its game profile's
            $profile = $tournament->gameProfile;
            $rules = $this->effectiveRules($tournament->scoring_rules, $profile?->scoring_rules);
            $tiebreakers = $this->effectiveRules($tournament->tiebreaker_config, $profile?->tiebreaker_config);

            $this->hasAdvancedRules = ($rules !== null && $this->scoreWeightsFrom($rules) === null)
                || ($tiebreakers !== null && $this->tiebreakersFrom($tiebreakers) === null);
            // Something the tab shows comes from the profile, not from the tournament
            $this->inheritedProfileName = $profile !== null
                && ($rules !== $this->effectiveRules($tournament->scoring_rules, null) || $tiebreakers !== $this->effectiveRules($tournament->tiebreaker_config, null))
                ? $profile->name
                : null;

            $this->configForm->fill([
                'tournament_enabled' => true,
                'name' => $tournament->name,
                'description' => $tournament->description,
                'max_rounds' => $tournament->max_rounds,
                'max_participants' => $tournament->max_participants,
                'min_participants' => $tournament->min_participants,
                'score_weights' => $this->scoreWeightsFrom($rules) ?? $this->getDefaultScoreWeights(),
                'tiebreakers' => $this->tiebreakersFrom($tiebreakers) ?? self::DEFAULT_TIEBREAKERS,
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
                'allow_guests' => TournamentFormDefaults::allowGuests(),
                'requires_check_in' => TournamentFormDefaults::requiresCheckIn(),
                'check_in_starts_before' => TournamentFormDefaults::checkInStartsBefore(),
                'result_reporting' => TournamentFormDefaults::resultReporting(),
            ]);
        }
    }

    public function configForm(Schema $schema): Schema
    {
        // The tournament, when there is one, so the end survey field loads and saves its choice
        return $schema
            ->components($this->getFormSchema())
            ->model(TournamentModel::where('event_id', $this->getOwnerRecord()->getKey())->first() ?? TournamentModel::class)
            ->statePath('configData');
    }

    /**
     * @return array<Component>
     */
    protected function getFormSchema(): array
    {
        return [
            Section::make(__('tournaments::messages.config.general'))
                ->schema([
                    Toggle::make('tournament_enabled')
                        ->label(__('tournaments::messages.config.tournament_enabled'))
                        ->helperText(__('tournaments::messages.config.tournament_enabled_help'))
                        // Switching it creates or deletes the tournament; save() checks it again
                        ->disabled(fn (): bool => ! $this->canSwitchTournament())
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
                        ->rule(fn (): Closure => RegistrationClosingDateRule::notAfter(
                            ($event = $this->getOwnerRecord()) instanceof EventModel ? $event->start_date : null
                        ))
                        ->nullable(),
                ])
                ->columns(2)
                ->visible(fn (Get $get): bool => (bool) $get('tournament_enabled')),

            Section::make(__('tournaments::messages.config.scoring'))
                ->schema([
                    Text::make(__('tournaments::messages.config.advanced_rules_notice'))
                        ->visible(fn (): bool => $this->hasAdvancedRules),
                    Text::make(fn (): string => __('tournaments::messages.config.profile_rules_notice', ['profile' => $this->inheritedProfileName]))
                        ->visible(fn (): bool => ! $this->hasAdvancedRules && $this->inheritedProfileName !== null),
                    Repeater::make('score_weights')
                        ->visible(fn (): bool => ! $this->hasAdvancedRules)
                        ->label(__('tournaments::messages.config.score_weights'))
                        ->schema([
                            TextInput::make('name')
                                ->label(__('tournaments::messages.config.score_weight_name'))
                                ->required(),
                            Select::make('key')
                                ->label(__('tournaments::messages.config.score_weight_key'))
                                ->options([
                                    'win' => __('tournaments::messages.condition_result.win'),
                                    'draw' => __('tournaments::messages.condition_result.draw'),
                                    'loss' => __('tournaments::messages.condition_result.loss'),
                                    'bye' => __('tournaments::messages.condition_result.bye'),
                                ])
                                ->native(false)
                                ->required(),
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
                        ->visible(fn (): bool => ! $this->hasAdvancedRules)
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
                    // Which survey goes out when it ends; hidden without the surveys module
                    EndSurveyField::forSubject(TournamentSurveySubjectSource::TYPE)->columnSpanFull(),
                ])
                ->columns(2)
                ->visible(fn (Get $get): bool => (bool) $get('tournament_enabled')),
        ];
    }

    public function save(): void
    {
        $eventId = $this->getOwnerRecord()->getKey();
        $tournament = $this->currentTournament();

        // What the save does decides the permission, checked before anything else: a Livewire
        // method can be called without the tab or its toggle. The raw state is what was sent;
        // the validated state leaves out the toggle when it is disabled
        if (! (bool) ($this->configData['tournament_enabled'] ?? false)) {
            if (! $tournament instanceof TournamentModel) {
                $this->notifyDisabled();

                return;
            }

            abort_unless(Gate::allows('delete', $tournament), 403);

            if ($tournament->participants()->exists()) {
                // Deleting takes the participants with it: only through the confirmation
                $this->mountAction('disableTournament');

                return;
            }

            $this->disableTournament($tournament);

            return;
        }

        abort_unless($tournament instanceof TournamentModel ? Gate::allows('update', $tournament) : Gate::allows('create', TournamentModel::class), 403);

        $formData = $this->configForm->getState();

        $data = [
            'event_id' => $eventId,
            'name' => $formData['name'],
            'description' => $formData['description'] ?? null,
            'max_rounds' => $formData['max_rounds'] ?? null,
            'max_participants' => $formData['max_participants'] ?? null,
            'min_participants' => $formData['min_participants'] ?? 2,
            'allow_guests' => $formData['allow_guests'] ?? false,
            'requires_check_in' => $formData['requires_check_in'] ?? false,
            'check_in_starts_before' => $formData['check_in_starts_before'] ?? null,
            'result_reporting' => $formData['result_reporting'] ?? 'admin_only',
            'registration_opens_at' => $formData['registration_opens_at'] ?? null,
            'registration_closes_at' => $formData['registration_closes_at'] ?? null,
        ];

        if (! $this->hasAdvancedRules) {
            $scoringRules = $this->scoringRulesFrom($formData['score_weights'] ?? $this->getDefaultScoreWeights());
            $tiebreakerConfig = $this->tiebreakerConfigFrom($formData['tiebreakers'] ?? self::DEFAULT_TIEBREAKERS);

            // With a game profile, only what the user changed becomes the tournament's own rules:
            // saving what the tab showed would hide later changes to the profile
            $profile = $tournament?->gameProfile;
            $shownRules = $this->effectiveRules($tournament?->scoring_rules, $profile?->scoring_rules);
            $shownTiebreakers = $this->effectiveRules($tournament?->tiebreaker_config, $profile?->tiebreaker_config);

            if ($profile === null || $scoringRules !== $this->scoringRulesFrom($this->scoreWeightsFrom($shownRules) ?? $this->getDefaultScoreWeights())) {
                $data['scoring_rules'] = $scoringRules;
            }

            if ($profile === null || $tiebreakerConfig !== $this->tiebreakerConfigFrom($this->tiebreakersFrom($shownTiebreakers) ?? self::DEFAULT_TIEBREAKERS)) {
                $data['tiebreaker_config'] = $tiebreakerConfig;
            }
        }

        if ($tournament instanceof TournamentModel) {
            $tournament->update($data);
        } else {
            $data['status'] = TournamentStatus::Draft->value;
            // Registrations and withdrawals notify this address: start with the person creating it,
            // who can change it in the tournament form
            $data['notification_email'] = (string) auth()->user()?->email;
            // The end survey field saves once the tournament exists
            $this->configForm->model(TournamentModel::create($data))->saveRelationships();
        }

        Notification::make()
            ->title(__('tournaments::messages.config.saved'))
            ->success()
            ->send();
    }

    /**
     * Switching off a tournament with participants: it deletes them with their rounds and
     * results, so it asks for a second confirmation that says so.
     */
    public function disableTournamentAction(): Action
    {
        return Action::make('disableTournament')
            ->label(__('tournaments::messages.config.disable'))
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading(__('tournaments::messages.config.disable_confirmation_title'))
            ->modalDescription(__('tournaments::messages.config.disable_confirmation_description'))
            ->modalSubmitActionLabel(__('tournaments::messages.config.disable_confirm'))
            ->schema([
                Checkbox::make('confirmation')
                    ->label(__('tournaments::messages.config.disable_confirmation_check'))
                    ->accepted()
                    ->required(),
            ])
            ->visible(fn (): bool => ($tournament = $this->currentTournament()) instanceof TournamentModel && Gate::allows('delete', $tournament))
            ->action(function (): void {
                $tournament = $this->currentTournament();

                if ($tournament instanceof TournamentModel && Gate::allows('delete', $tournament)) {
                    $this->disableTournament($tournament);
                }
            });
    }

    /**
     * Whether the toggle may create (no tournament yet) or delete (a tournament) the tournament.
     */
    public function canSwitchTournament(): bool
    {
        $tournament = $this->currentTournament();

        return $tournament instanceof TournamentModel ? Gate::allows('delete', $tournament) : Gate::allows('create', TournamentModel::class);
    }

    /**
     * Whether the save button can do anything: create, change or switch off the tournament.
     */
    public function canSaveTournament(): bool
    {
        $tournament = $this->currentTournament();

        return $tournament instanceof TournamentModel
            ? Gate::allows('update', $tournament) || Gate::allows('delete', $tournament)
            : Gate::allows('create', TournamentModel::class);
    }

    private function currentTournament(): ?TournamentModel
    {
        return TournamentModel::where('event_id', $this->getOwnerRecord()->getKey())->first();
    }

    private function disableTournament(TournamentModel $tournament): void
    {
        // As a model: the surveys hear of it
        $tournament->delete();

        $this->notifyDisabled();
    }

    private function notifyDisabled(): void
    {
        Notification::make()
            ->title(__('tournaments::messages.config.tournament_disabled'))
            ->success()
            ->send();
    }

    /**
     * The tournament's own rules, else its game profile's, as the standings pick them; null when neither has any.
     *
     * @param  array<mixed>|null  $own
     * @param  array<mixed>|null  $profile
     * @return array<mixed>|null
     */
    private function effectiveRules(?array $own, ?array $profile): ?array
    {
        if ($own !== null && $own !== []) {
            return $own;
        }

        return $profile !== null && $profile !== [] ? $profile : null;
    }

    /**
     * Points per result as the tournament's scoring rules.
     *
     * @param  array<mixed>  $weights
     * @return list<array<string, mixed>>
     */
    private function scoringRulesFrom(array $weights): array
    {
        $rules = [];
        foreach ($weights as $weight) {
            if (! is_array($weight) || ! isset($weight['key'], $weight['name'])) {
                continue;
            }

            $rules[] = ScoringRule::fromArray([
                'name' => (string) $weight['name'],
                'points' => (float) ($weight['points'] ?? 0),
                'priority' => 0,
                'condition' => ['type' => ConditionType::Result->value, 'result_value' => (string) $weight['key'], 'stat' => null, 'operator' => null, 'value' => null],
            ])->toArray();
        }

        return $rules;
    }

    /**
     * Chosen tiebreakers as the tournament's tiebreaker definitions, in order.
     *
     * @param  array<mixed>  $tiebreakers
     * @return list<array<string, mixed>>
     */
    private function tiebreakerConfigFrom(array $tiebreakers): array
    {
        $config = [];
        foreach ($tiebreakers as $value) {
            $tiebreaker = is_string($value) ? Tiebreaker::tryFrom($value) : null;
            if ($tiebreaker !== null) {
                $config[] = (new TiebreakerDefinition($tiebreaker->type()->value, $tiebreaker->label(), $tiebreaker->type()))->toArray();
            }
        }

        return $config;
    }

    /**
     * The tournament's rules as points per result, or null when a rule is not a plain result rule.
     *
     * @param  array<mixed>|null  $rules
     * @return list<array{name: string, key: string, points: float}>|null
     */
    private function scoreWeightsFrom(?array $rules): ?array
    {
        if ($rules === null || $rules === []) {
            return null;
        }

        $weights = [];
        foreach ($rules as $rule) {
            $condition = is_array($rule) ? ($rule['condition'] ?? null) : null;
            if (! is_array($condition) || ($condition['type'] ?? null) !== ConditionType::Result->value) {
                return null;
            }

            $weights[] = [
                'name' => (string) ($rule['name'] ?? ''),
                'key' => (string) ($condition['result_value'] ?? ''),
                'points' => (float) ($rule['points'] ?? 0),
            ];
        }

        return $weights;
    }

    /**
     * The tournament's tiebreakers as the tab's choices, or null when one has no choice here
     * or carries settings the tab cannot show.
     *
     * @param  array<mixed>|null  $config
     * @return list<string>|null
     */
    private function tiebreakersFrom(?array $config): ?array
    {
        if ($config === null || $config === []) {
            return null;
        }

        $values = [];
        foreach ($config as $definition) {
            $type = is_array($definition) && is_string($definition['type'] ?? null) ? TiebreakerType::tryFrom($definition['type']) : null;
            $tiebreaker = $type !== null ? Tiebreaker::fromType($type) : null;
            // Anything the tab cannot show (direction, floor, stat, own key) would be lost on save
            if (! $tiebreaker instanceof Tiebreaker
                || ($definition['direction'] ?? 'desc') !== 'desc'
                || ($definition['min_value'] ?? null) !== null
                || ($definition['stat'] ?? null) !== null
                || ($definition['key'] ?? $tiebreaker->type()->value) !== $tiebreaker->type()->value) {
                return null;
            }

            $values[] = $tiebreaker->value;
        }

        return $values;
    }

    /**
     * @return array<array{name: string, key: string, points: float}>
     */
    private function getDefaultScoreWeights(): array
    {
        return [
            ['name' => __('tournaments::messages.condition_result.win'), 'key' => 'win', 'points' => 3],
            ['name' => __('tournaments::messages.condition_result.draw'), 'key' => 'draw', 'points' => 1],
            ['name' => __('tournaments::messages.condition_result.loss'), 'key' => 'loss', 'points' => 0],
            ['name' => __('tournaments::messages.condition_result.bye'), 'key' => 'bye', 'points' => 3],
        ];
    }
}
