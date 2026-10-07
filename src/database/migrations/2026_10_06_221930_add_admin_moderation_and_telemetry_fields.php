<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_active')->default(true)->after('role');
            $table->text('blocked_reason')->nullable()->after('is_active');
        });

        Schema::table('projects', function (Blueprint $table) {
            $table->boolean('is_visible_in_showcase')->default(true)->after('category');
            $table->boolean('is_suspended')->default(false)->after('is_visible_in_showcase');
            $table->text('suspension_reason')->nullable()->after('is_suspended');
            $table->unsignedInteger('demo_runs_count')->default(0)->after('suspension_reason');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['is_active', 'blocked_reason']);
        });

        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn(['is_visible_in_showcase', 'is_suspended', 'suspension_reason', 'demo_runs_count']);
        });
    }
};
