<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Telegram players. No email/password: identity comes from signed initData.
        Schema::create('users', function (Blueprint $table) {
            $table->id();

            // Telegram identity — replaces the prototype's phone + password signup.
            $table->unsignedBigInteger('telegram_id')->unique();
            $table->unsignedBigInteger('chat_id')->nullable()->index();
            $table->string('username')->nullable()->index();
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('photo_url')->nullable();
            $table->boolean('is_telegram_premium')->default(false);
            $table->boolean('allows_write_to_pm')->default(true);

            // Onboarding answers
            $table->string('native_lang', 8)->default('uz');   // uz | ru | ky | kk | kaa
            $table->json('study_days')->nullable();            // ["Du","Se",...]
            $table->time('reminder_at')->nullable();
            $table->string('cefr_level', 2)->nullable();       // A0..C1
            $table->unsignedSmallInteger('daily_goal')->default(10);
            $table->string('teacher_code')->nullable();
            $table->boolean('onboarded')->default(false);
            $table->boolean('dark_mode')->default(false);
            $table->string('timezone')->default('Asia/Tashkent');

            // Habit loop
            $table->unsignedInteger('streak_days')->default(0);
            $table->unsignedInteger('best_streak')->default(0);
            $table->date('last_practiced_date')->nullable();
            $table->unsignedInteger('words_learned')->default(0);  // denormalised counter

            // Growth
            $table->foreignId('referred_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('referrals_count')->default(0);
            $table->string('source')->nullable();

            // State
            $table->boolean('has_blocked_bot')->default(false);
            $table->boolean('is_banned')->default(false);
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->index('last_practiced_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
