<?php

namespace App\Models\Sistema;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EnvioEmailDetalle extends Model
{
    use HasFactory;

    protected $connection = 'eco';

    protected $table = "envio_email_detalles";

    protected $fillable = [
        'id_email',
        'email',
        'event',
        'message_id',
        'response',
        'timestamp',
        'error_code',
        'error_message',
        'campos_adicionales'
    ];

    protected $casts = [
        'campos_adicionales' => 'array',
        'tls' => 'boolean',
    ];

    public function email()
    {
        return $this->belongsTo(EnvioEmail::class, 'id_email');
    }
}
