<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Who organizes each tournament: they manage its surveys on the public site
        Schema::create('tournaments_organizers', function (Blueprint $table): void {
            $table->uuid('tournament_id');
            $table->uuid('user_id');

            $table->primary(['tournament_id', 'user_id']);
            $table->index('user_id');

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
        Schema::dropIfExists('tournaments_organizers');
    }
};
