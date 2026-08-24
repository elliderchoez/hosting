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
        Schema::table('contact_logs', function (Blueprint $table) {
            $table->string('sender_name')->nullable()->after('student_id');
            $table->string('sender_email')->nullable()->after('sender_name');
            $table->string('sender_company')->nullable()->after('sender_email');
            $table->text('reply')->nullable()->after('message');
            $table->timestamp('replied_at')->nullable()->after('reply');
            $table->timestamp('read_at')->nullable()->after('replied_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('contact_logs', function (Blueprint $table) {
            $table->dropColumn([
                'sender_name',
                'sender_email',
                'sender_company',
                'reply',
                'replied_at',
                'read_at',
            ]);
        });
    }
};
