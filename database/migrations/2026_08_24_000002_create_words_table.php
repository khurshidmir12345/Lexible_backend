<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The shared dictionary every player searches when filling a category.
        // Filled once from the dictionary API, then served from here forever.
        Schema::create('words', function (Blueprint $table) {
            $table->id();
            $table->string('word');                            // "beautiful"
            $table->string('normalized')->unique();            // lookup key
            $table->string('part_of_speech', 32)->nullable();  // adjective | noun | verb ...
            $table->string('transcription')->nullable();       // /ˈbjuːtɪfəl/
            $table->string('audio_url')->nullable();           // remote mp3 from the API
            $table->string('audio_path')->nullable();          // our own copy, once downloaded

            // {"uz":["chiroyli","go'zal"], "ru":["красивый"], "ky":[], "kk":[], "kaa":[]}
            $table->json('translations')->nullable();
            $table->json('definition')->nullable();            // {"en":"...", "uz":"..."}
            $table->json('example')->nullable();               // {"en":"...", "uz":"..."}
            $table->json('synonyms')->nullable();

            // Visuals: 3D icon is the goal, emoji is the fallback while it is missing.
            $table->string('icon_path')->nullable();
            $table->string('emoji', 16)->nullable();

            $table->string('cefr_level', 2)->nullable();
            $table->unsignedInteger('frequency_rank')->nullable();
            $table->unsignedInteger('usage_count')->default(0);   // how often players add it

            $table->string('source', 32)->default('api');         // api | manual | import
            $table->boolean('needs_review')->default(true);       // admin has not verified yet
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['is_active', 'frequency_rank']);
            $table->index(['needs_review', 'is_active']);
            $table->index('cefr_level');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('words');
    }
};
