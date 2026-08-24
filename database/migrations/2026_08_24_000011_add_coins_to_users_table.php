<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // The v2 home screen shows a coin balance: +1 per correct answer,
            // +10 for winning a duel. Spent later on premium paths and hints.
            $table->unsignedInteger('coins')->default(0)->after('words_learned');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('coins');
        });
    }
};
