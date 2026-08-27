<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // The teacher's public ID — "TCHR-2381" on UT-09. `teacher_code` is
            // the opposite direction: what a *student* typed during onboarding.
            $table->string('teacher_ref', 16)->nullable()->unique()->after('teacher_code');

            // UT-08 / UT-08b: either the teacher buys seats for the class, or
            // every student pays for themselves.
            $table->string('billing_mode', 16)->default('teacher')->after('teacher_ref');
            $table->unsignedSmallInteger('plan_seats')->default(0)->after('billing_mode');
            $table->unsignedSmallInteger('plan_requested_seats')->nullable()->after('plan_seats');
            $table->timestamp('plan_until')->nullable()->after('plan_requested_seats');
        });

        Schema::table('group_members', function (Blueprint $table) {
            // Only meaningful while the teacher is on `billing_mode = student`.
            $table->timestamp('paid_until')->nullable()->after('joined_at');
        });
    }

    public function down(): void
    {
        Schema::table('group_members', function (Blueprint $table) {
            $table->dropColumn('paid_until');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'teacher_ref', 'billing_mode', 'plan_seats', 'plan_requested_seats', 'plan_until',
            ]);
        });
    }
};
