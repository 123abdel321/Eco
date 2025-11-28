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
        Schema::connection('clientes')->create('credenciales_envio', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade')->nullable();
            $table->string('tipo'); // 'whatsapp', 'email', 'sms'
            $table->string('proveedor'); // 'twilio', 'smtp', 'sendgrid', etc
            $table->text('credenciales'); // JSON encriptado con las credenciales
            $table->boolean('activo')->default(true);
            $table->boolean('es_predeterminado')->default(false); // Si es la credencial por defecto del usuario
            $table->timestamp('ultima_verificacion')->nullable();
            $table->string('estado_verificacion')->nullable(); // 'verificado', 'error', 'pendiente'
            $table->text('mensaje_verificacion')->nullable();
            $table->timestamps();
            
            // Índices
            $table->index(['user_id', 'tipo', 'activo']);
            $table->index(['user_id', 'tipo', 'es_predeterminado']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('credencial_envios');
    }
};
