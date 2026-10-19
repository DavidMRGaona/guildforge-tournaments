<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tournaments_participants', function (Blueprint $table): void {
            // When the reminder before the tournament went out (once per registration)
            $table->dateTime('reminder_sent_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('tournaments_participants', function (Blueprint $table): void {
            $table->dropColumn('reminder_sent_at');
        });
    }
};
