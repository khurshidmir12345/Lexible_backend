<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A whole class answers one frozen question set at the same time. The
        // teacher opens the lobby, waits for students, then starts everyone.
        Schema::create('competitions', function (Blueprint $table) {
            $table->id();
            $table->string('code', 12)->unique();               // shared in the invite link
            $table->foreignId('teacher_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('group_id')->constrained()->cascadeOnDelete();
            $table->foreignId('path_stage_id')->nullable()->constrained()->nullOnDelete();

            $table->json('types');
            $table->json('word_ids');                           // frozen — identical for everyone
            $table->unsignedSmallInteger('questions_count')->default(0);
            $table->string('status', 16)->default('lobby');     // lobby | playing | finished | cancelled

            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['group_id', 'status']);
            $table->index(['teacher_id', 'status']);
        });

        Schema::create('competition_players', function (Blueprint $table) {
            $table->id();
            $table->foreignId('competition_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('test_session_id')->nullable()->constrained()->nullOnDelete();

            $table->string('status', 16)->default('joined');    // joined | playing | finished
            $table->unsignedSmallInteger('score')->default(0);
            $table->unsignedSmallInteger('total')->default(0);
            $table->unsignedInteger('duration_ms')->default(0);
            $table->unsignedSmallInteger('rank')->nullable();

            $table->timestamp('joined_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->unique(['competition_id', 'user_id']);
            $table->index(['competition_id', 'status']);
        });

        Schema::table('test_sessions', function (Blueprint $table) {
            $table->foreignId('competition_id')->nullable()->after('duel_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('test_sessions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('competition_id');
        });

        Schema::dropIfExists('competition_players');
        Schema::dropIfExists('competitions');
    }
};
