<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // `role` defaults to student, so it cannot itself tell us whether
            // UT-00 has ever been answered. Without this the role question
            // comes back at every launch for anyone who stops mid-onboarding.
            $table->boolean('role_chosen')->default(false)->after('role');
        });

        // Everybody already in the database has been past that screen.
        Schema::getConnection()->table('users')
            ->where(fn ($query) => $query->where('onboarded', true)->orWhere('role', 'teacher'))
            ->update(['role_chosen' => true]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('role_chosen');
        });
    }
};
