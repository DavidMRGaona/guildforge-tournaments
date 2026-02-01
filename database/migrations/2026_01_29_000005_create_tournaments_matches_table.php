<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tournaments_matches', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('round_id');
            $table->uuid('player_1_id');
            $table->uuid('player_2_id')->nullable(); // null for BYE
            $table->integer('table_number')->nullable();
            $table->string('result')->default('not_played');
            $table->integer('player_1_score')->nullable();
            $table->json('player_1_stats')->nullable();
            $table->integer('player_2_score')->nullable();
            $table->json('player_2_stats')->nullable();
            $table->uuid('reported_by_id')->nullable();
            $table->timestamp('reported_at')->nullable();
            $table->uuid('confirmed_by_id')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->boolean('is_disputed')->default(false);
            $table->timestamps();

            $table->index(['round_id', 'result']);

            $table->foreign('round_id')
                ->references('id')
                ->on('tournaments_rounds')
                ->onDelete('cascade');

            $table->foreign('player_1_id')
                ->references('id')
                ->on('tournaments_participants')
                ->onDelete('cascade');

            $table->foreign('player_2_id')
                ->references('id')
                ->on('tournaments_participants')
                ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tournaments_matches');
    }
};