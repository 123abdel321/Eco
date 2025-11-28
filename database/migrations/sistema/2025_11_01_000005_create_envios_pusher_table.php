<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{

    public function up(): void
    {
        Schema::connection('eco')->create('envios_pusher', function (Blueprint $table) {
            $table->id();
            $table->string('app_source')->nullable(); // Identificador de la app cliente (ej. "Empresa A")
            $table->unsignedBigInteger('user_id')->nullable(); // ID del usuario en la app cliente
            $table->string('channel');
            $table->string('event');
            $table->json('payload'); // El mensaje completo
            $table->enum('status', ['enviado', 'fallido'])->default('enviado');
            $table->timestamps();
            
            // Índices para búsqueda rápida
            $table->index('created_at');
            $table->index('user_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('eco')->dropIfExists('envio_whatsapp');
    }
};
