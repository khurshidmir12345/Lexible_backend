<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('paths', function (Blueprint $table) {
            // Which exercises the class may play on this path; null means all.
            $table->json('types')->nullable()->after('emoji');
        });
    }

    public function down(): void
    {
        Schema::table('paths', function (Blueprint $table) {
            $table->dropColumn('types');
        });
    }
};
