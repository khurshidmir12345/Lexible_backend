<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The search index behind the "add words" screen.
     *
     * Searching `translations->uz LIKE '%x%'` on a JSON column walks every
     * row (about a second over 400k words). This table holds one flat, lower-
     * cased term per row — the English headword and every translation of an
     * active, translated word — so a prefix search is a single index range.
     * Only active words with a translation in that locale are indexed, which
     * is exactly the set the screen may offer.
     */
    public function up(): void
    {
        Schema::create('word_search_terms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('word_id')->constrained()->cascadeOnDelete();
            $table->string('locale', 4);               // the learner's language the word is usable in
            $table->string('kind', 2);                 // en (headword) | tr (translation)
            $table->string('term', 120);               // lower-cased, trimmed
            $table->unsignedInteger('rank');           // frequency_rank, unknown words last

            $table->index(['locale', 'term', 'rank']);
            $table->index(['locale', 'kind', 'rank']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('word_search_terms');
    }
};
