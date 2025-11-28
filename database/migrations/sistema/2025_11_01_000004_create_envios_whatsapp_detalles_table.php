<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{

    public function up()
    {
        Schema::connection('eco')->create('envios_whatsapp_detalles', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('id_whatsapp');
            $table->string('phone');
            $table->string('event');
            $table->string('message_id')->nullable();
            $table->text('response')->nullable();
            $table->bigInteger('timestamp')->nullable();
            $table->string('error_code')->nullable();
            $table->text('error_message')->nullable();
            $table->json('campos_adicionales')->nullable();
            $table->timestamps();

            $table->index(['phone']);
            $table->index(['event']);
            $table->index(['message_id']);
            $table->index(['timestamp']);
        });
    }

    public function down()
    {
        Schema::connection('eco')->dropIfExists('envio_whatsapp_detalles');
    }
};
