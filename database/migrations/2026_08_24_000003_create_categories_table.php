<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One node on the player's road map. Players name it and fill it themselves.
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('position');            // 1, 2, 3 ... on the map
            $table->string('title')->nullable();                 // null until the player names it
            $table->string('type', 16)->default('normal');       // normal | exam
            $table->string('status', 16)->default('locked');     // locked | in_progress | completed
            $table->unsignedTinyInteger('progress')->default(0); // 0..100
            $table->date('unlock_date')->nullable();             // drives the seasonal decorations
            $table->boolean('practiced')->default(false);        // enables the "all / only weak" prompt
            $table->unsignedSmallInteger('words_count')->default(0);
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'position']);
            $table->index(['user_id', 'status']);
        });

        Schema::create('category_word', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->constrained()->cascadeOnDelete();
            $table->foreignId('word_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamp('created_at')->nullable();

            $table->unique(['category_id', 'word_id']);
            $table->index(['category_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('category_word');
        Schema::dropIfExists('categories');
    }
};
