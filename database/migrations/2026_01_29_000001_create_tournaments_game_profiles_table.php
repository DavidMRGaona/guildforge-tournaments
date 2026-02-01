<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tournaments_game_profiles', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->json('stat_definitions');
            $table->json('scoring_rules');
            $table->json('tiebreaker_config');
            $table->json('pairing_config');
            $table->boolean('is_system')->default(false);
            $table->timestamps();

            $table->index('slug');
            $table->index('is_system');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tournaments_game_profiles');
    }
};