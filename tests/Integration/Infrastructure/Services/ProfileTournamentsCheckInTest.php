<?php

declare(strict_types=1);

namespace Modules\Tournaments\Tests\Integration\Infrastructure\Services;

use App\Infrastructure\Persistence\Eloquent\Models\EventModel;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use Illuminate\Support\Str;
use Modules\Tournaments\Domain\Enums\ParticipantStatus;
use Modules\Tournaments\Domain\Enums\TournamentStatus;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\ParticipantModel;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\TournamentModel;
use Modules\Tournaments\Infrastructure\Services\ProfileTournamentsDataProvider;
use Modules\Tournaments\Tests\Support\PinsTheClock;
use Tests\Support\Modules\ModuleTestCase;

/**
 * The «Mis torneos» cards offer the check-in under the rule the check-in itself applies, as the
 * tournament page does (TournamentShowCheckInTest).
 */
final class ProfileTournamentsCheckInTest extends ModuleTestCase
{
    use PinsTheClock;

    protected ?string $moduleName = 'tournaments';

    protected bool $autoEnableModule = true;

    public function test_a_registered_participant_may_check_in_while_the_window_is_open(): void
    {
        $user = UserModel::factory()->create();
        $this->registration($this->tournament(), $user, ParticipantStatus::Registered);

        $this->assertTrue($this->card($user)['canCheckIn']);
    }

    public function test_an_unconfirmed_registration_waits_when_the_organisation_confirms_by_hand(): void
    {
        $user = UserModel::factory()->create();
        $registration = $this->registration($this->tournament(['requires_manual_confirmation' => true]), $user, ParticipantStatus::Registered);

        $this->assertFalse($this->card($user)['canCheckIn']);

        $registration->update(['status' => ParticipantStatus::Confirmed->value]);

        $this->assertTrue($this->card($user)['canCheckIn']);
    }

    public function test_the_check_in_is_not_offered_outside_the_window_or_once_done(): void
    {
        $before = UserModel::factory()->create();
        $this->registration($this->tournament(startsInMinutes: 120), $before, ParticipantStatus::Registered);
        $this->assertFalse($this->card($before)['canCheckIn']);

        $done = UserModel::factory()->create();
        $this->registration($this->tournament(), $done, ParticipantStatus::CheckedIn);
        $this->assertFalse($this->card($done)['canCheckIn']);

        $selfOff = UserModel::factory()->create();
        $this->registration($this->tournament(['self_check_in_allowed' => false]), $selfOff, ParticipantStatus::Registered);
        $this->assertFalse($this->card($selfOff)['canCheckIn']);
    }

    /**
     * @return array<string, mixed>
     */
    private function card(UserModel $user): array
    {
        $data = app(ProfileTournamentsDataProvider::class)->getDataForUser((string) $user->id);
        $cards = [...$data['upcoming'], ...$data['inProgress'], ...$data['past']];
        $this->assertCount(1, $cards);

        return $cards[0];
    }

    private function registration(TournamentModel $tournament, UserModel $user, ParticipantStatus $status): ParticipantModel
    {
        return ParticipantModel::create([
            'id' => (string) Str::uuid(),
            'tournament_id' => $tournament->id,
            'user_id' => $user->id,
            'status' => $status->value,
            'registered_at' => now()->subDay(),
            'checked_in_at' => $status === ParticipantStatus::CheckedIn ? now()->subMinute() : null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function tournament(array $attributes = [], int $startsInMinutes = 10): TournamentModel
    {
        $event = EventModel::factory()->published()->create(['start_date' => now()->addMinutes($startsInMinutes)]);

        return TournamentModel::create([
            'event_id' => $event->id,
            'name' => 'Torneo de check-in',
            'slug' => 'torneo-'.Str::lower(Str::random(10)),
            'status' => TournamentStatus::RegistrationClosed,
            'allow_guests' => true,
            'result_reporting' => 'admin_only',
            'notification_email' => 'test@example.com',
            'requires_check_in' => true,
            'self_check_in_allowed' => true,
            'check_in_starts_before' => 30,
            ...$attributes,
        ]);
    }
}
