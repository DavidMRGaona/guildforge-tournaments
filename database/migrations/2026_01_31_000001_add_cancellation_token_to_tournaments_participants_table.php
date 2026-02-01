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
            $table->string('cancellation_token', 64)->nullable()->unique()->after('guest_email');
        });
    }

    public function down(): void
    {
        Schema::table('tournaments_participants', function (Blueprint $table): void {
            $table->dropColumn('cancellation_token');
        });
    }
};
