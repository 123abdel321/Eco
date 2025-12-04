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
        Schema::connection('eco')->create('envio_email_detalles', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('id_email');
            $table->string('email');
            $table->string('event');
            $table->string('message_id')->nullable();
            $table->text('response')->nullable();
            $table->bigInteger('timestamp')->nullable();
            $table->string('error_code')->nullable();
            $table->text('error_message')->nullable();
            $table->json('campos_adicionales')->nullable();
            $table->timestamps();

            $table->foreign('id_email')->references('id')->on('envios_email')->onDelete('cascade');
            $table->index(['email']);
            $table->index(['event']);
            $table->index(['message_id']);
            $table->index(['timestamp']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('eco')->dropIfExists('envio_email_detalles');
    }
};
