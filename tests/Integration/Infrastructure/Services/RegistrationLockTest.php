<?php

declare(strict_types=1);

namespace Modules\Tournaments\Tests\Integration\Infrastructure\Services;

use App\Infrastructure\Persistence\Eloquent\Models\EventModel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Tournaments\Application\Services\RegistrationLockInterface;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\TournamentModel;
use Modules\Tournaments\Infrastructure\Services\DatabaseRegistrationLock;
use Tests\Support\Modules\ModuleTestCase;

final class RegistrationLockTest extends ModuleTestCase
{
    protected ?string $moduleName = 'tournaments';

    protected bool $autoEnableModule = true;

    private TournamentModel $tournament;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tournament = TournamentModel::create([
            'event_id' => EventModel::factory()->create()->id,
            'name' => 'Torneo',
            'slug' => 'torneo-'.Str::lower(Str::random(10)),
            'status' => 'draft',
            'result_reporting' => 'admin_only',
        ]);
    }

    public function test_the_lock_is_bound_to_the_database_implementation(): void
    {
        $this->assertInstanceOf(DatabaseRegistrationLock::class, app(RegistrationLockInterface::class));
    }

    public function test_the_lock_runs_the_callback_in_a_transaction_and_returns_its_value(): void
    {
        $level = DB::transactionLevel();

        $result = (new DatabaseRegistrationLock())->forTournament($this->tournament->id, fn (): int => DB::transactionLevel());

        $this->assertSame($level + 1, $result);
    }

    public function test_the_lock_of_a_participant_and_of_a_token_find_the_tournament(): void
    {
        $id = (string) Str::uuid();
        DB::table('tournaments_participants')->insert([
            'id' => $id,
            'tournament_id' => $this->tournament->id,
            'guest_name' => 'Ana',
            'guest_email' => 'ana@example.com',
            'status' => 'registered',
            'confirmation_token' => 'token-de-prueba',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $lock = new DatabaseRegistrationLock();
        $level = DB::transactionLevel();

        $this->assertSame($level + 1, $lock->forParticipant($id, fn (): int => DB::transactionLevel()));
        $this->assertSame($level + 1, $lock->forConfirmationToken('token-de-prueba', fn (): int => DB::transactionLevel()));
    }

    public function test_without_a_participant_the_callback_still_runs_in_a_transaction(): void
    {
        $lock = new DatabaseRegistrationLock();
        $level = DB::transactionLevel();

        $this->assertSame($level + 1, $lock->forParticipant((string) Str::uuid(), fn (): int => DB::transactionLevel()));
        $this->assertSame($level + 1, $lock->forConfirmationToken('desconocido', fn (): int => DB::transactionLevel()));
    }
}
