<?php

namespace App\Models\Sistema;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
//MODELS
use App\Models\User;

class EnvioPusher extends Model
{
    use HasFactory;

    protected $connection = 'eco';

    protected $table = "envios_pusher";

    protected $fillable = [
        'user_id',
        'type',
        'message_id',
        'sg_message_id',
        'email',
        'contexto',
        'status',
        'campos_adicionales',
    ];

    protected $casts = [
        'campos_adicionales' => 'array',
    ];

    // Relación con el usuario (en la conexión clientes)
    public function user()
    {
        return $this->setConnection('clientes')->belongsTo(User::class, 'user_id');
    }

    public function detalles()
    {
        return $this->hasMany(EnvioEmailDetalle::class, 'id_email');
    }
}
