<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Icon suggestions per word for the admin review screen.
 *
 * The suggestions come from the offline embedding search (the server has no
 * embeddings), so they are shipped as a file and keyed by the word itself —
 * word ids differ between the local and the server database.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('icon_candidates', function (Blueprint $table) {
            $table->id();
            $table->string('normalized')->unique();
            $table->json('slugs');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('icon_candidates');
    }
};
