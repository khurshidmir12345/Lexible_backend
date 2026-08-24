<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Mastery is six-dimensional: a player can recognise a word on a flashcard
        // but still not be able to spell it. The UI shows each dimension separately
        // and their average as the word's overall percentage.
        Schema::create('word_progress', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('word_id')->constrained()->cascadeOnDelete();

            $table->unsignedTinyInteger('m_card')->default(0);
            $table->unsignedTinyInteger('m_uz2en')->default(0);
            $table->unsignedTinyInteger('m_en2uz')->default(0);
            $table->unsignedTinyInteger('m_spell')->default(0);
            $table->unsignedTinyInteger('m_image')->default(0);
            $table->unsignedTinyInteger('m_match')->default(0);
            $table->unsignedTinyInteger('overall')->default(0); // average, kept for sorting

            $table->unsignedSmallInteger('correct_count')->default(0);
            $table->unsignedSmallInteger('wrong_count')->default(0);
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_practiced_at')->nullable();
            $table->timestamp('due_at')->nullable();            // spaced repetition hook
            $table->boolean('is_learned')->default(false);      // overall >= 70
            $table->timestamps();

            $table->unique(['user_id', 'word_id']);
            $table->index(['user_id', 'overall']);
            $table->index(['user_id', 'due_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('word_progress');
    }
};
