<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A player's report waiting for confirmation: the match keeps no result until then
        Schema::create('tournaments_match_reports', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('match_id')->unique();
            $table->string('result');
            $table->integer('player_1_score')->nullable();
            $table->integer('player_2_score')->nullable();
            $table->json('player_1_stats')->nullable();
            $table->json('player_2_stats')->nullable();
            $table->uuid('reported_by_id');
            $table->timestamp('reported_at');
            $table->uuid('disputed_by_id')->nullable();
            $table->text('dispute_reason')->nullable();
            $table->timestamp('disputed_at')->nullable();
            $table->timestamps();

            $table->foreign('match_id')
                ->references('id')
                ->on('tournaments_matches')
                ->onDelete('cascade');

            $table->foreign('reported_by_id')
                ->references('id')
                ->on('users')
                ->onDelete('cascade');

            $table->foreign('disputed_by_id')
                ->references('id')
                ->on('users')
                ->nullOnDelete();
        });

        Schema::table('tournaments_match_history', function (Blueprint $table): void {
            $table->string('action')->default('recorded');
        });

        // The dispute lives on the report now
        Schema::table('tournaments_matches', function (Blueprint $table): void {
            $table->dropColumn('is_disputed');
        });
    }

    public function down(): void
    {
        Schema::table('tournaments_matches', function (Blueprint $table): void {
            $table->boolean('is_disputed')->default(false);
        });

        Schema::table('tournaments_match_history', function (Blueprint $table): void {
            $table->dropColumn('action');
        });

        Schema::dropIfExists('tournaments_match_reports');
    }
};
