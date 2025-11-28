<?php

namespace App\Models\Sistema;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EnvioWhatsappDetalle extends Model
{
    use HasFactory;

    protected $connection = 'eco';

    protected $table = "envios_whatsapp_detalles";

    protected $fillable = [
        'id_whatsapp',
        'phone',
        'event',
        'message_id',
        'response',
        'timestamp',
        'error_code',
        'error_message',
        'campos_adicionales',
    ];

    protected $casts = [
        'campos_adicionales' => 'array',
        'timestamp' => 'datetime',
    ];

    public function whatsapp()
    {
        return $this->belongsTo(EnvioWhatsapp::class, 'id_whatsapp');
    }
}
