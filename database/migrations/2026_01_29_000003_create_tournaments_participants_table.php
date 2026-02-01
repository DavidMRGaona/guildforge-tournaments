<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tournaments_participants', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tournament_id');
            $table->uuid('user_id')->nullable();
            $table->string('guest_name')->nullable();
            $table->string('guest_email')->nullable();
            $table->string('cancellation_token', 64)->nullable()->unique();
            $table->string('status')->default('registered');
            $table->integer('seed')->nullable();
            $table->boolean('has_received_bye')->default(false);
            $table->timestamp('registered_at')->nullable();
            $table->timestamp('checked_in_at')->nullable();
            $table->timestamps();

            $table->unique(['tournament_id', 'user_id']);
            $table->index(['tournament_id', 'status']);

            $table->foreign('tournament_id')
                ->references('id')
                ->on('tournaments_tournaments')
                ->onDelete('cascade');

            $table->foreign('user_id')
                ->references('id')
                ->on('users')
                ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tournaments_participants');
    }
};