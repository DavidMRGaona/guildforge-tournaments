<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tournaments_tournaments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('event_id')->unique();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->string('image_public_id')->nullable();
            $table->string('status')->default('draft');
            $table->integer('max_rounds')->nullable();
            $table->integer('current_round')->default(0);
            $table->integer('max_participants')->nullable();
            $table->integer('min_participants')->default(2);
            $table->boolean('allow_guests')->default(false);
            $table->boolean('requires_manual_confirmation')->default(false);
            $table->uuid('game_profile_id')->nullable();
            $table->json('stat_definitions')->nullable();
            $table->json('scoring_rules')->nullable();
            $table->json('tiebreaker_config')->nullable();
            $table->json('pairing_config')->nullable();
            $table->boolean('show_participants')->default(true);
            $table->string('notification_email')->default('');
            $table->boolean('self_check_in_allowed')->default(false);
            $table->json('allowed_roles')->nullable();
            $table->boolean('requires_check_in')->default(false);
            $table->integer('check_in_starts_before')->nullable();
            $table->string('result_reporting')->default('admin_only');
            $table->timestamp('registration_opens_at')->nullable();
            $table->timestamp('registration_closes_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index('slug');
            $table->index('status');

            $table->foreign('event_id')
                ->references('id')
                ->on('events')
                ->onDelete('cascade');

            $table->foreign('game_profile_id')
                ->references('id')
                ->on('tournaments_game_profiles')
                ->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tournaments_tournaments');
    }
};