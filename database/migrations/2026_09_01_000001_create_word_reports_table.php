<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('word_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // The word may later be deleted or merged; the report keeps the
            // text the player actually saw, so it stays actionable.
            $table->foreignId('word_id')->nullable()->constrained()->nullOnDelete();
            $table->string('word', 120);
            $table->string('text', 500);
            // What the admin answered through the bot, kept for the record.
            $table->string('reply', 500)->nullable();
            $table->boolean('resolved')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('word_reports');
    }
};
