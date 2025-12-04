<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{

    public function up(): void
    {
        Schema::connection('eco')->create('envios_whatsapp', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('plantilla_id')->nullable();
            $table->string('message_id')->nullable();
            $table->string('phone');
            $table->string('contexto')->nullable();
            $table->enum('status', ['en_cola', 'enviado', 'entregado', 'abierto', 'leido', 'fallido'])->default('en_cola');
            $table->json('campos_adicionales')->nullable();
            $table->json('filter_metadata')->nullable()->comment('Metadatos para indexación y filtrado rápido, ej: {"cliente_id": 1}');
            $table->timestamps();

            $table->index(['user_id']);
            $table->index(['message_id']);
            $table->index(['status']);
            $table->index(['created_at']);
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
