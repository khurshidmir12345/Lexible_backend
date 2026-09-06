<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bot reminders: an on/off switch (the API already accepted it, the column
 * never existed) and two "done for today" dates so a sweep that runs every
 * few minutes never sends the same reminder twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('reminders_enabled')->default(true)->after('reminder_at');
            $table->date('reminded_on')->nullable()->after('reminders_enabled');
            $table->date('streak_warned_on')->nullable()->after('reminded_on');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['reminders_enabled', 'reminded_on', 'streak_warned_on']);
        });
    }
};
