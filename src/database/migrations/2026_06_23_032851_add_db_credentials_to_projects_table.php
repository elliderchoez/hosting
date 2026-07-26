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
            $table->string('db_name', 100)->nullable();
            $table->string('db_user', 100)->nullable();
            $table->string('db_password', 255)->nullable();
            $table->string('db_driver', 20)->default('pgsql');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn(['db_name', 'db_user', 'db_password', 'db_driver']);
        });
    }
};
