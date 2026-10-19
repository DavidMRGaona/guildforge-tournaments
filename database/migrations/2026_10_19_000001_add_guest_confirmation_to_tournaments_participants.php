<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tournaments_participants', function (Blueprint $table): void {
            $table->dateTime('email_confirmed_at')->nullable();
            $table->dateTime('confirmation_expires_at')->nullable();
            $table->string('confirmation_token', 64)->nullable()->unique();
            $table->dateTime('confirmation_sent_at')->nullable();
            $table->string('confirmation_mode', 8)->nullable();
            $table->string('fingerprint', 64)->nullable();
        });

        // Every existing registration was made before confirmation existed: it counts as confirmed
        DB::table('tournaments_participants')->whereNull('email_confirmed_at')->update([
            'email_confirmed_at' => DB::raw('COALESCE(created_at, CURRENT_TIMESTAMP)'),
        ]);

        // Backstop under the tournament lock: one active registration per guest email and tournament.
        // Partial and expression indexes are not in the schema builder; both drivers take this SQL.
        DB::statement(
            'CREATE UNIQUE INDEX tournaments_participants_active_guest_email_unique '
            .'ON tournaments_participants (tournament_id, lower(guest_email)) '
            ."WHERE user_id IS NULL AND guest_email IS NOT NULL AND status IN ('registered', 'confirmed', 'checked_in')"
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS tournaments_participants_active_guest_email_unique');

        Schema::table('tournaments_participants', function (Blueprint $table): void {
            $table->dropUnique(['confirmation_token']);
            $table->dropColumn([
                'email_confirmed_at',
                'confirmation_expires_at',
                'confirmation_token',
                'confirmation_sent_at',
                'confirmation_mode',
                'fingerprint',
            ]);
        });
    }
};
