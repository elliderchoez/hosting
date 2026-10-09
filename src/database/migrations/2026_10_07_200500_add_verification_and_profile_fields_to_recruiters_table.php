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
        Schema::table('recruiters', function (Blueprint $table) {
            $table->string('account_type', 20)->default('company')->after('id'); // 'company' or 'recruiter'
            $table->string('tax_id', 30)->nullable()->after('company'); // RUC de empresa o Cédula/RUC personal
            $table->string('website_url', 255)->nullable()->after('tax_id'); // Sitio web corporativo
            $table->string('linkedin_url', 255)->nullable()->after('website_url'); // Perfil LinkedIn del reclutador
            $table->string('phone', 35)->nullable()->after('linkedin_url'); // Teléfono institucional o WhatsApp
            $table->string('status', 20)->default('pending')->after('verified'); // 'pending', 'approved', 'rejected'
            $table->text('rejection_reason')->nullable()->after('status');
            $table->timestamp('reviewed_at')->nullable()->after('rejection_reason');
            $table->foreignUuid('reviewed_by')->nullable()->after('reviewed_at')->constrained('users')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('recruiters', function (Blueprint $table) {
            $table->dropForeign(['reviewed_by']);
            $table->dropColumn([
                'account_type',
                'tax_id',
                'website_url',
                'linkedin_url',
                'phone',
                'status',
                'rejection_reason',
                'reviewed_at',
                'reviewed_by',
            ]);
        });
    }
};
