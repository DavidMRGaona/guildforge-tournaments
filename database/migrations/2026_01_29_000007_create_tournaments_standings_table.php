<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tournaments_standings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tournament_id');
            $table->uuid('participant_id');
            $table->integer('rank')->default(0);
            $table->integer('matches_played')->default(0);
            $table->integer('wins')->default(0);
            $table->integer('draws')->default(0);
            $table->integer('losses')->default(0);
            $table->integer('byes')->default(0);
            $table->decimal('points', 8, 2)->default(0);
            $table->decimal('buchholz', 8, 2)->default(0);
            $table->decimal('median_buchholz', 8, 2)->default(0);
            $table->decimal('progressive', 8, 2)->default(0);
            $table->decimal('opponent_win_percentage', 5, 4)->default(0);
            $table->json('accumulated_stats')->nullable();
            $table->json('calculated_tiebreakers')->nullable();
            $table->timestamps();

            $table->unique(['tournament_id', 'participant_id']);
            $table->index(['tournament_id', 'rank']);

            $table->foreign('tournament_id')
                ->references('id')
                ->on('tournaments_tournaments')
                ->onDelete('cascade');

            $table->foreign('participant_id')
                ->references('id')
                ->on('tournaments_participants')
                ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tournaments_standings');
    }
};