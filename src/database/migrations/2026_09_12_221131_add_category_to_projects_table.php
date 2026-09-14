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
            $table->string('category')->default('Herramientas y Calculadoras')->nullable();
        });

        // Actualizar proyectos existentes con categorías coherentes
        \Illuminate\Support\Facades\DB::table('projects')->whereRaw("LOWER(name) LIKE '%crater%'")->update(['category' => 'Finanzas y Facturación']);
        \Illuminate\Support\Facades\DB::table('projects')->whereRaw("LOWER(name) LIKE '%tienda%' OR LOWER(name) LIKE '%shop%' OR LOWER(name) LIKE '%store%'")->update(['category' => 'Comercio Electrónico']);
        \Illuminate\Support\Facades\DB::table('projects')->whereRaw("LOWER(name) LIKE '%calc%' OR LOWER(name) LIKE '%tool%'")->update(['category' => 'Herramientas y Calculadoras']);
        \Illuminate\Support\Facades\DB::table('projects')->whereRaw("LOWER(name) LIKE '%hospital%' OR LOWER(name) LIKE '%med%' OR LOWER(name) LIKE '%salud%'")->update(['category' => 'Salud y Medicina']);
        \Illuminate\Support\Facades\DB::table('projects')->whereRaw("LOWER(name) LIKE '%academ%' OR LOWER(name) LIKE '%edu%' OR LOWER(name) LIKE '%escolar%'")->update(['category' => 'Educación y Gestión Académica']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn('category');
        });
    }
};
