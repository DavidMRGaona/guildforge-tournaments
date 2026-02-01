<?php

declare(strict_types=1);

namespace Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models;

use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Tournaments\Domain\Enums\ParticipantStatus;

/**
 * @property string $id
 * @property string $tournament_id
 * @property string|null $user_id
 * @property string|null $guest_name
 * @property string|null $guest_email
 * @property string|null $cancellation_token
 * @property ParticipantStatus $status
 * @property int|null $seed
 * @property bool $has_received_bye
 * @property \Carbon\Carbon|null $registered_at
 * @property \Carbon\Carbon|null $checked_in_at
 * @property \Carbon\Carbon|null $created_at
 * @property \Carbon\Carbon|null $updated_at
 * @property-read TournamentModel $tournament
 * @property-read UserModel|null $user
 * @property-read \Illuminate\Database\Eloquent\Collection<int, MatchModel> $matchesAsPlayer1
 * @property-read \Illuminate\Database\Eloquent\Collection<int, MatchModel> $matchesAsPlayer2
 * @property-read StandingModel|null $standing
 * @property-read string $participant_name
 */
final class ParticipantModel extends Model
{
    use HasUuids;

    protected $table = 'tournaments_participants';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'id',
        'tournament_id',
        'user_id',
        'guest_name',
        'guest_email',
        'cancellation_token',
        'status',
        'seed',
        'has_received_bye',
        'registered_at',
        'checked_in_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ParticipantStatus::class,
            'seed' => 'integer',
            'has_received_bye' => 'boolean',
            'registered_at' => 'datetime',
            'checked_in_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<TournamentModel, self>
     */
    public function tournament(): BelongsTo
    {
        return $this->belongsTo(TournamentModel::class, 'tournament_id');
    }

    /**
     * @return BelongsTo<UserModel, self>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(UserModel::class, 'user_id');
    }

    /**
     * @return HasMany<MatchModel, self>
     */
    public function matchesAsPlayer1(): HasMany
    {
        return $this->hasMany(MatchModel::class, 'player_1_id');
    }

    /**
     * @return HasMany<MatchModel, self>
     */
    public function matchesAsPlayer2(): HasMany
    {
        return $this->hasMany(MatchModel::class, 'player_2_id');
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\HasOne<StandingModel, self>
     */
    public function standing(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(StandingModel::class, 'participant_id');
    }

    /**
     * Get the participant's display name.
     */
    public function getParticipantNameAttribute(): string
    {
        if ($this->user !== null) {
            return $this->user->name;
        }

        return $this->guest_name ?? __('tournaments::messages.participants.unknown');
    }
}
