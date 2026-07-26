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
        Schema::create('contact_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('recruiter_id')->constrained('recruiters')->onDelete('cascade');
            $table->foreignUuid('student_id')->constrained('users')->onDelete('cascade');
            $table->text('message');
            $table->timestamp('created_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('contact_logs');
    }
};
