<?php

declare(strict_types=1);

namespace Modules\Tournaments\Tests\Integration\Database;

use App\Infrastructure\Persistence\Eloquent\Models\EventModel;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Str;
use Modules\Tournaments\Domain\Enums\ParticipantStatus;
use Modules\Tournaments\Domain\Enums\TournamentStatus;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\ParticipantModel;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\TournamentModel;
use Tests\Support\Modules\ModuleTestCase;

/**
 * The registrations left «Inscrito» in tournaments that have not started and ask neither for
 * manual confirmation nor for check-in become «Confirmado», as a new one would.
 */
final class ConfirmRegistrationsWithoutRequirementsMigrationTest extends ModuleTestCase
{
    protected ?string $moduleName = 'tournaments';

    protected bool $autoEnableModule = true;

    public function test_it_confirms_only_the_registrations_with_nothing_pending(): void
    {
        $open = $this->tournament();
        $account = $this->participant($open, ['user_id' => UserModel::factory()->create()->id]);
        $confirmedGuest = $this->participant($open, ['guest_name' => 'Ana', 'guest_email' => 'ana@example.com', 'email_confirmed_at' => now()]);
        $pendingGuest = $this->participant($open, ['guest_name' => 'Juan', 'guest_email' => 'juan@example.com']);
        $withdrawn = $this->participant($open, ['user_id' => UserModel::factory()->create()->id, 'status' => ParticipantStatus::Withdrawn->value]);

        $manual = $this->participant($this->tournament(['requires_manual_confirmation' => true]), ['user_id' => UserModel::factory()->create()->id]);
        $checkIn = $this->participant($this->tournament(['requires_check_in' => true]), ['user_id' => UserModel::factory()->create()->id]);
        $started = $this->participant($this->tournament(['status' => TournamentStatus::InProgress->value]), ['user_id' => UserModel::factory()->create()->id]);
        $finished = $this->participant($this->tournament(['status' => TournamentStatus::Finished->value]), ['user_id' => UserModel::factory()->create()->id]);

        $migration = require dirname(__DIR__, 3).'/database/migrations/2026_11_07_000001_confirm_registrations_of_tournaments_without_requirements.php';
        $this->assertInstanceOf(Migration::class, $migration);
        if (! method_exists($migration, 'up')) {
            $this->fail('The migration must have up().');
        }

        $migration->up();

        $this->assertSame(ParticipantStatus::Confirmed, $account->refresh()->status);
        $this->assertSame(ParticipantStatus::Confirmed, $confirmedGuest->refresh()->status);
        $this->assertSame(ParticipantStatus::Registered, $pendingGuest->refresh()->status);
        $this->assertSame(ParticipantStatus::Withdrawn, $withdrawn->refresh()->status);
        $this->assertSame(ParticipantStatus::Registered, $manual->refresh()->status);
        $this->assertSame(ParticipantStatus::Registered, $checkIn->refresh()->status);
        $this->assertSame(ParticipantStatus::Registered, $started->refresh()->status);
        $this->assertSame(ParticipantStatus::Registered, $finished->refresh()->status);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function tournament(array $attributes = []): TournamentModel
    {
        return TournamentModel::create([
            'event_id' => EventModel::factory()->create()->id,
            'name' => 'Torneo',
            'slug' => 'torneo-'.Str::lower(Str::random(10)),
            'status' => TournamentStatus::RegistrationOpen->value,
            'result_reporting' => 'admin_only',
            ...$attributes,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function participant(TournamentModel $tournament, array $attributes): ParticipantModel
    {
        return ParticipantModel::create([
            'tournament_id' => $tournament->id,
            'status' => ParticipantStatus::Registered->value,
            'registered_at' => now()->subDay(),
            ...$attributes,
        ]);
    }
}
