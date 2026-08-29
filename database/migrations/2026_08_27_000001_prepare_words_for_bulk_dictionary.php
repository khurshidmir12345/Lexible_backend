<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('words', function (Blueprint $table) {
            // Translating 400k words is a long job run in batches over days,
            // so each row has to carry its own state rather than the job
            // holding a list in memory.
            $table->string('translation_status', 16)->default('pending')->after('source');
            $table->timestamp('translated_at')->nullable()->after('translation_status');
            $table->unsignedTinyInteger('translation_attempts')->default(0)->after('translated_at');

            // Where the Uzbek came from, so a human correction is never
            // overwritten by a later machine pass.
            $table->string('translation_source', 24)->nullable()->after('translation_attempts');

            // Wiktionary lists a word once per part of speech and etymology.
            // We keep one row per word and record the rest here so the app can
            // still say "noun, also a verb".
            $table->json('pos_all')->nullable()->after('part_of_speech');

            $table->index(['translation_status', 'frequency_rank']);
        });
    }

    public function down(): void
    {
        Schema::table('words', function (Blueprint $table) {
            $table->dropIndex(['translation_status', 'frequency_rank']);
            $table->dropColumn([
                'translation_status', 'translated_at', 'translation_attempts',
                'translation_source', 'pos_all',
            ]);
        });
    }
};
