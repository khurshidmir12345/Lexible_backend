<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Tiers are reached by total coins ever earned, so spending the
            // balance later never takes a reward away.
            $table->unsignedInteger('coins_lifetime')->default(0)->after('coins');
            $table->timestamp('premium_until')->nullable()->after('coins_lifetime');
            $table->unsignedTinyInteger('premium_tier')->default(0)->after('premium_until');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['coins_lifetime', 'premium_until', 'premium_tier']);
        });
    }
};
