<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Seed of the "random" tiebreaker, so a tournament's random order holds across recalculations.
     */
    public function up(): void
    {
        Schema::table('tournaments_tournaments', function (Blueprint $table): void {
            $table->string('random_seed', 32)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('tournaments_tournaments', function (Blueprint $table): void {
            $table->dropColumn('random_seed');
        });
    }
};
