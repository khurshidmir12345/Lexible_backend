<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // UT-MD2: a teacher can run a stage as an open game — no group, just a
        // link they hand out. Anybody who opens the link joins.
        Schema::table('competitions', function (Blueprint $table) {
            $table->foreignId('group_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('competitions', function (Blueprint $table) {
            $table->foreignId('group_id')->nullable(false)->change();
        });
    }
};
