<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tournaments_rounds', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tournament_id');
            $table->integer('round_number');
            $table->string('status')->default('pending');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['tournament_id', 'round_number']);

            $table->foreign('tournament_id')
                ->references('id')
                ->on('tournaments_tournaments')
                ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tournaments_rounds');
    }
};