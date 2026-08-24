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
        Schema::table('projects', function (Blueprint $table) {
            $table->uuid('backend_project_id')->nullable()->after('user_id');
            $table->boolean('is_backend_service')->default(false)->after('backend_project_id');

            $table->foreign('backend_project_id')
                ->references('id')
                ->on('projects')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropForeign(['backend_project_id']);
            $table->dropColumn(['backend_project_id', 'is_backend_service']);
        });
    }
};
