<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'eco';
    
    public function up(): void
    {
        Schema::connection('eco')->create('configuracion_envios', function (Blueprint $table) {
            $table->id();
            $table->enum('tipo', ['email', 'whatsapp'])->unique(); // Único por tipo
            $table->integer('limite_por_minuto')->default(20);
            $table->integer('limite_por_hora')->default(100);
            $table->integer('limite_por_dia')->default(1000);
            $table->boolean('activo')->default(true);
            $table->json('configuracion')->nullable();
            $table->timestamps();
        });

        // Insertar configuraciones por defecto
        DB::connection('eco')->table('configuracion_envios')->insert([
            [
                'tipo' => 'email',
                'limite_por_minuto' => 20,
                'limite_por_hora' => 100,
                'limite_por_dia' => 1000,
                'activo' => true,
                'configuracion' => json_encode(['prioridad' => 'normal']),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'tipo' => 'whatsapp',
                'limite_por_minuto' => 10,
                'limite_por_hora' => 50,
                'limite_por_dia' => 500,
                'activo' => true,
                'configuracion' => json_encode(['prioridad' => 'alta']),
                'created_at' => now(),
                'updated_at' => now(),
            ]
        ]);
    }

    public function down(): void
    {
        Schema::connection('eco')->dropIfExists('configuracion_envios');
    }
};