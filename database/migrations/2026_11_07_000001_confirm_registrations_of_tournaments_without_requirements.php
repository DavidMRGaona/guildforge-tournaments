<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * A tournament that asks neither for manual confirmation nor for check-in now confirms its
 * registrations as soon as nothing is pending. Registrations left «registered» before that, in
 * tournaments that have not started, are confirmed the same way: accounts, and guests whose email
 * is confirmed. Started or finished tournaments keep their participants as they were.
 */
return new class extends Migration
{
    public function up(): void
    {
        $tournaments = DB::table('tournaments_tournaments')
            ->select('id')
            ->where('requires_manual_confirmation', false)
            ->where('requires_check_in', false)
            ->whereIn('status', ['draft', 'registration_open', 'registration_closed']);

        DB::table('tournaments_participants')
            ->where('status', 'registered')
            ->whereIn('tournament_id', $tournaments)
            ->where(static function (Builder $query): void {
                $query->whereNotNull('user_id')->orWhereNotNull('email_confirmed_at');
            })
            ->update(['status' => 'confirmed', 'updated_at' => now()]);
    }

    public function down(): void
    {
        // Nothing to undo: a confirmed registration of such a tournament is what a new one gets
    }
};
