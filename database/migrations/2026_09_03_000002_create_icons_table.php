<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The 3D icon library and the link from a word to its picture.
     *
     * The icons are a bought set of ten thousand PNGs with a meta.json that
     * names each one and files it under a category and tags. That metadata is
     * what a later re-match or an admin search runs on, so it lives in its own
     * table; the files themselves sit on the public disk as WebP in two sizes.
     *
     * `words.icon_path` already existed as the path the app shows. It stays —
     * every response reads it — and `icon_id` says which library entry it
     * came from, `icon_source` how it was chosen (exact | llm | manual), and
     * `icon_confidence` how sure the matcher was, so weak matches can be
     * reviewed or dropped without redoing the strong ones.
     */
    public function up(): void
    {
        Schema::create('icons', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 120)->unique();   // file name without extension
            $table->string('title', 160);
            $table->string('category', 60)->index();
            $table->json('tags')->nullable();
            $table->unsignedSmallInteger('volume')->default(1);
            $table->string('path', 160);             // public-disk path of the 256px WebP
            $table->timestamps();
        });

        Schema::table('words', function (Blueprint $table) {
            $table->foreignId('icon_id')->nullable()->after('icon_path')->constrained()->nullOnDelete();
            $table->string('icon_source', 12)->nullable()->after('icon_id');
            $table->unsignedTinyInteger('icon_confidence')->nullable()->after('icon_source');
        });
    }

    public function down(): void
    {
        Schema::table('words', function (Blueprint $table) {
            $table->dropConstrainedForeignId('icon_id');
            $table->dropColumn(['icon_source', 'icon_confidence']);
        });

        Schema::dropIfExists('icons');
    }
};
