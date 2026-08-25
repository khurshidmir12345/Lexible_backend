<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Both roles sign in through Telegram; this only decides which
            // side of the app they land on.
            $table->string('role', 16)->default('student')->after('onboarded');
            $table->string('teacher_code')->nullable()->change();
        });

        // A teacher's curriculum: an ordered set of stages they fill themselves.
        Schema::create('paths', function (Blueprint $table) {
            $table->id();
            $table->foreignId('teacher_id')->constrained('users')->cascadeOnDelete();
            $table->string('title');
            $table->string('subtitle')->nullable();
            $table->string('emoji', 16)->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('stages_count')->default(0);
            $table->timestamps();

            $table->index(['teacher_id', 'is_active']);
        });

        Schema::create('path_stages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('path_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            $table->string('title')->nullable();
            $table->string('type', 16)->default('normal');   // normal | exam
            $table->unsignedSmallInteger('words_count')->default(0);
            $table->timestamps();

            $table->unique(['path_id', 'position']);
        });

        Schema::create('path_stage_word', function (Blueprint $table) {
            $table->id();
            $table->foreignId('path_stage_id')->constrained()->cascadeOnDelete();
            $table->foreignId('word_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->unique(['path_stage_id', 'word_id']);
        });

        // A class. Students join with the code and inherit the attached path.
        Schema::create('groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('teacher_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('path_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title');
            $table->string('subtitle')->nullable();
            $table->string('code', 24)->unique();
            $table->string('badge', 8)->nullable();          // "5A"
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('members_count')->default(0);
            $table->timestamps();

            $table->index(['teacher_id', 'is_active']);
        });

        Schema::create('group_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('status', 16)->default('pending'); // pending | active | removed
            $table->timestamp('joined_at')->nullable();
            $table->timestamps();

            $table->unique(['group_id', 'user_id']);
            $table->index(['user_id', 'status']);
        });

        // A group stage becomes a real category for each student, so all the
        // existing gameplay — tests, mastery, duels — works untouched.
        Schema::table('categories', function (Blueprint $table) {
            $table->foreignId('group_id')->nullable()->after('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('path_stage_id')->nullable()->after('group_id')->constrained()->nullOnDelete();
            $table->index(['group_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->dropConstrainedForeignId('group_id');
            $table->dropConstrainedForeignId('path_stage_id');
        });

        Schema::dropIfExists('group_members');
        Schema::dropIfExists('groups');
        Schema::dropIfExists('path_stage_word');
        Schema::dropIfExists('path_stages');
        Schema::dropIfExists('paths');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('role');
        });
    }
};
