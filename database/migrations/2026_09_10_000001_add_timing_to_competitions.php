<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A teacher may put a clock on the round: everybody who is still
        // answering when it runs out is finished with what they have, the
        // words they never reached counted as wrong.
        Schema::table('competitions', function (Blueprint $table) {
            $table->unsignedSmallInteger('duration_minutes')->nullable()->after('questions_count');
            $table->timestamp('deadline_at')->nullable()->after('started_at');
            // When the roster was last called in through the bot.
            $table->timestamp('notified_at')->nullable()->after('expires_at');
        });

        Schema::table('competition_players', function (Blueprint $table) {
            // Finished by the clock (or the teacher), not by the player.
            $table->boolean('timed_out')->default(false)->after('rank');
        });
    }

    public function down(): void
    {
        Schema::table('competition_players', function (Blueprint $table) {
            $table->dropColumn('timed_out');
        });

        Schema::table('competitions', function (Blueprint $table) {
            $table->dropColumn(['duration_minutes', 'deadline_at', 'notified_at']);
        });
    }
};
