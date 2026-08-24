<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One run through a category: the player picks which of the six test
        // types to include, and whether to drill everything or only weak words.
        Schema::create('test_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('duel_id')->nullable()->index();   // set for duel rounds

            $table->json('types');                               // ["card","spell",...]
            $table->string('scope', 8)->default('all');          // all | wrong
            $table->string('status', 16)->default('active');     // active | finished | abandoned

            $table->unsignedSmallInteger('questions_count')->default(0);
            $table->unsignedSmallInteger('answered_count')->default(0);
            $table->unsignedSmallInteger('correct_count')->default(0);
            $table->unsignedSmallInteger('wrong_count')->default(0);
            $table->unsignedInteger('duration_ms')->default(0);

            $table->json('payload')->nullable();                 // generated questions + answer key
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
        });

        Schema::create('test_answers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('test_session_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('word_id')->constrained()->cascadeOnDelete();
            $table->string('type', 16);                          // card | uz2en | en2uz | spell | image | match
            $table->boolean('is_correct');
            $table->string('given_answer')->nullable();
            $table->unsignedInteger('response_ms')->default(0);
            $table->timestamp('created_at')->nullable();

            $table->index(['user_id', 'word_id']);
            $table->index(['user_id', 'created_at']);            // powers the weekly chart
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('test_answers');
        Schema::dropIfExists('test_sessions');
    }
};
