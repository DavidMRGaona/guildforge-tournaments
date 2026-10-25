<?php

declare(strict_types=1);

namespace Modules\Tournaments\Tests\Integration\Database;

use App\Infrastructure\Persistence\Eloquent\Models\EventModel;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\TournamentModel;
use Tests\Support\Modules\ModuleTestCase;

final class GuestConfirmationMigrationTest extends ModuleTestCase
{
    protected ?string $moduleName = 'tournaments';

    protected bool $autoEnableModule = true;

    public function test_backfill_marks_existing_rows_confirmed(): void
    {
        $migration = require dirname(__DIR__, 3).'/database/migrations/2026_10_19_000001_add_guest_confirmation_to_tournaments_participants.php';
        $this->assertInstanceOf(Migration::class, $migration);
        if (! method_exists($migration, 'up') || ! method_exists($migration, 'down')) {
            $this->fail('The migration must be reversible.');
        }

        $tournamentId = $this->createTournament();
        $migration->down();

        DB::table('tournaments_participants')->insert([
            'id' => (string) Str::uuid(),
            'tournament_id' => $tournamentId,
            'guest_name' => 'Ana',
            'guest_email' => 'ana@example.com',
            'status' => 'registered',
            'created_at' => '2030-02-03 04:05:06',
            'updated_at' => '2030-02-03 04:05:06',
        ]);

        $migration->up();

        $row = DB::table('tournaments_participants')->first();
        $this->assertNotNull($row);
        $this->assertSame('2030-02-03 04:05:06', $row->email_confirmed_at);
        $this->assertNull($row->confirmation_mode);
        $this->assertNull($row->confirmation_token);
    }

    public function test_index_refuses_a_second_active_guest_with_the_same_email_in_another_case(): void
    {
        $tournamentId = $this->createTournament();
        $this->insertGuest($tournamentId, 'Ana@Example.com');

        $this->expectException(UniqueConstraintViolationException::class);

        $this->insertGuest($tournamentId, 'ana@example.com');
    }

    public function test_index_allows_the_same_email_when_the_first_registration_is_withdrawn(): void
    {
        $tournamentId = $this->createTournament();
        $this->insertGuest($tournamentId, 'Ana@Example.com', 'withdrawn');

        $this->insertGuest($tournamentId, 'ana@example.com');

        $this->assertSame(2, DB::table('tournaments_participants')->count());
    }

    public function test_index_allows_the_same_email_when_the_first_registration_expired(): void
    {
        $tournamentId = $this->createTournament();
        $this->insertGuest($tournamentId, 'ana@example.com', 'expired');

        $this->insertGuest($tournamentId, 'ana@example.com');

        $this->assertSame(2, DB::table('tournaments_participants')->count());
    }

    public function test_index_allows_the_same_email_in_another_tournament(): void
    {
        $this->insertGuest($this->createTournament(), 'ana@example.com');

        $this->insertGuest($this->createTournament(), 'ana@example.com');

        $this->assertSame(2, DB::table('tournaments_participants')->count());
    }

    private function insertGuest(string $tournamentId, string $email, string $status = 'registered'): void
    {
        DB::table('tournaments_participants')->insert([
            'id' => (string) Str::uuid(),
            'tournament_id' => $tournamentId,
            'guest_name' => 'Ana',
            'guest_email' => $email,
            'status' => $status,
            'created_at' => '2030-02-03 04:05:06',
            'updated_at' => '2030-02-03 04:05:06',
        ]);
    }

    private function createTournament(): string
    {
        return TournamentModel::create([
            'event_id' => EventModel::factory()->create()->id,
            'name' => 'Torneo',
            'slug' => 'torneo-'.Str::lower(Str::random(10)),
            'status' => 'draft',
            'result_reporting' => 'admin_only',
        ])->id;
    }
}
