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
        Schema::create('profiles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->onDelete('cascade');
            $table->text('bio')->nullable();
            $table->string('phone', 20)->nullable();
            $table->string('github_username', 100)->nullable();
            $table->string('linkedin_url', 255)->nullable();
            $table->json('skills')->nullable();
            $table->json('education')->nullable();
            $table->string('cv_pdf_path', 255)->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('profiles');
    }
};
