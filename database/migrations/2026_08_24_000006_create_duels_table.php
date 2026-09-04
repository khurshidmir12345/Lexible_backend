<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Two players answer the same questions; more correct wins, time breaks a tie.
        Schema::create('duels', function (Blueprint $table) {
            $table->id();
            $table->string('code', 12)->unique();               // shared in the invite link
            $table->foreignId('host_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('guest_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('category_id')->nullable()->constrained()->nullOnDelete();

            $table->json('types');                              // test types both players take
            $table->json('word_ids');                           // frozen question set — identical for both
            $table->string('status', 16)->default('waiting');   // waiting | ready | playing | finished | cancelled

            $table->unsignedSmallInteger('host_score')->default(0);
            $table->unsignedSmallInteger('guest_score')->default(0);
            $table->unsignedInteger('host_ms')->default(0);
            $table->unsignedInteger('guest_ms')->default(0);
            $table->boolean('host_finished')->default(false);
            $table->boolean('guest_finished')->default(false);
            $table->foreignId('winner_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['host_id', 'status']);
            $table->index(['guest_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('duels');
    }
};
