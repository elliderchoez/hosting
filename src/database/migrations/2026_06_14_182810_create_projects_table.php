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
        Schema::create('projects', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->onDelete('cascade');
            $table->string('name', 100);
            $table->string('subdomain', 63)->unique();
            $table->string('github_repo_url', 255);
            $table->string('branch', 50)->default('main');
            $table->string('language', 20)->nullable(); // 'nodejs', 'php', 'python'
            $table->string('status', 20)->default('stopped'); // 'stopped', 'running', 'sleeping', 'building'
            $table->string('container_id', 128)->nullable();
            $table->integer('port')->nullable();
            $table->timestamp('last_visited_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('projects');
    }
};
