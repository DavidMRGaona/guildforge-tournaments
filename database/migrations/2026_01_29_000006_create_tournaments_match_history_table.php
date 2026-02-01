<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tournaments_match_history', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('match_id');
            $table->string('previous_result')->nullable();
            $table->string('new_result');
            $table->integer('previous_player_1_score')->nullable();
            $table->integer('new_player_1_score')->nullable();
            $table->integer('previous_player_2_score')->nullable();
            $table->integer('new_player_2_score')->nullable();
            $table->uuid('changed_by_id');
            $table->text('reason')->nullable();
            $table->timestamp('changed_at');
            $table->timestamps();

            $table->index(['match_id', 'changed_at']);

            $table->foreign('match_id')
                ->references('id')
                ->on('tournaments_matches')
                ->onDelete('cascade');

            $table->foreign('changed_by_id')
                ->references('id')
                ->on('users')
                ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tournaments_match_history');
    }
};