<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('words', function (Blueprint $table) {
            /*
             * Searchable is not the same as teachable.
             *
             * "the", "of" and "is" top every frequency list, so a dictionary
             * ordered by frequency would hand a beginner a stage full of
             * grammar words. They stay in the table — a teacher typing them
             * must still find them — but they are never dealt out unasked.
             */
            $table->boolean('is_teachable')->default(true)->after('is_active');

            $table->index(['is_teachable', 'frequency_rank']);
        });
    }

    public function down(): void
    {
        Schema::table('words', function (Blueprint $table) {
            $table->dropIndex(['is_teachable', 'frequency_rank']);
            $table->dropColumn('is_teachable');
        });
    }
};
