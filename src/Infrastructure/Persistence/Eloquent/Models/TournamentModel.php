<?php

declare(strict_types=1);

namespace Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models;

use App\Infrastructure\Persistence\Eloquent\Concerns\DeletesCloudinaryImages;
use App\Infrastructure\Persistence\Eloquent\Concerns\HasSlug;
use App\Infrastructure\Persistence\Eloquent\Models\EventModel;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Tournaments\Domain\Enums\ResultReporting;
use Modules\Tournaments\Domain\Enums\TournamentStatus;

/**
 * @property string $id
 * @property string $event_id
 * @property string $name
 * @property string $slug
 * @property string|null $description
 * @property string|null $image_public_id
 * @property TournamentStatus $status
 * @property int|null $max_rounds
 * @property int $current_round
 * @property int|null $max_participants
 * @property int $min_participants
 * @property bool $allow_guests
 * @property bool $requires_manual_confirmation
 * @property array<string>|null $allowed_roles
 * @property bool $requires_check_in
 * @property int|null $check_in_starts_before
 * @property ResultReporting $result_reporting
 * @property string|null $game_profile_id
 * @property array<string, mixed>|null $stat_definitions
 * @property array<string, mixed>|null $scoring_rules
 * @property array<string, mixed>|null $tiebreaker_config
 * @property array<string, mixed>|null $pairing_config
 * @property bool $show_participants
 * @property string $notification_email
 * @property bool $self_check_in_allowed
 * @property \Carbon\Carbon|null $registration_opens_at
 * @property \Carbon\Carbon|null $registration_closes_at
 * @property \Carbon\Carbon|null $started_at
 * @property \Carbon\Carbon|null $completed_at
 * @property \Carbon\Carbon|null $created_at
 * @property \Carbon\Carbon|null $updated_at
 * @property-read EventModel $event
 * @property-read \Illuminate\Database\Eloquent\Collection<int, ParticipantModel> $participants
 * @property-read \Illuminate\Database\Eloquent\Collection<int, RoundModel> $rounds
 * @property-read \Illuminate\Database\Eloquent\Collection<int, StandingModel> $standings
 * @property-read GameProfileModel|null $gameProfile
 */
final class TournamentModel extends Model
{
    use DeletesCloudinaryImages;
    use HasSlug;
    use HasUuids;

    /** @var array<string> */
    protected array $cloudinaryImageFields = ['image_public_id'];

    protected $table = 'tournaments_tournaments';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'id',
        'event_id',
        'name',
        'slug',
        'description',
        'image_public_id',
        'status',
        'max_rounds',
        'current_round',
        'max_participants',
        'min_participants',
        'allow_guests',
        'requires_manual_confirmation',
        'allowed_roles',
        'requires_check_in',
        'check_in_starts_before',
        'result_reporting',
        'game_profile_id',
        'stat_definitions',
        'scoring_rules',
        'tiebreaker_config',
        'pairing_config',
        'show_participants',
        'notification_email',
        'self_check_in_allowed',
        'registration_opens_at',
        'registration_closes_at',
        'started_at',
        'completed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => TournamentStatus::class,
            'result_reporting' => ResultReporting::class,
            'allowed_roles' => 'array',
            'allow_guests' => 'boolean',
            'requires_manual_confirmation' => 'boolean',
            'requires_check_in' => 'boolean',
            'max_rounds' => 'integer',
            'current_round' => 'integer',
            'max_participants' => 'integer',
            'min_participants' => 'integer',
            'check_in_starts_before' => 'integer',
            'registration_opens_at' => 'datetime',
            'registration_closes_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'stat_definitions' => 'array',
            'scoring_rules' => 'array',
            'tiebreaker_config' => 'array',
            'pairing_config' => 'array',
            'show_participants' => 'boolean',
            'self_check_in_allowed' => 'boolean',
        ];
    }

    public function getSlugEntityType(): string
    {
        return 'tournament';
    }

    protected function getSlugSourceField(): string
    {
        return 'name';
    }

    /**
     * @return BelongsTo<EventModel, self>
     */
    public function event(): BelongsTo
    {
        return $this->belongsTo(EventModel::class, 'event_id');
    }

    /**
     * @return HasMany<ParticipantModel, self>
     */
    public function participants(): HasMany
    {
        return $this->hasMany(ParticipantModel::class, 'tournament_id');
    }

    /**
     * @return HasMany<RoundModel, self>
     */
    public function rounds(): HasMany
    {
        return $this->hasMany(RoundModel::class, 'tournament_id');
    }

    /**
     * @return HasMany<StandingModel, self>
     */
    public function standings(): HasMany
    {
        return $this->hasMany(StandingModel::class, 'tournament_id');
    }

    /**
     * @return BelongsTo<GameProfileModel, self>
     */
    public function gameProfile(): BelongsTo
    {
        return $this->belongsTo(GameProfileModel::class, 'game_profile_id');
    }
}
