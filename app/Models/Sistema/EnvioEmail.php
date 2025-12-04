<?php

namespace App\Models\Sistema;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
//MODELS
use App\Models\User;

class EnvioEmail extends Model
{
    use HasFactory;

    protected $connection = 'eco';

    protected $table = "envios_email";

    protected $fillable = [
        'user_id',
        'message_id',
        'email',
        'contexto',
        'status',
        'campos_adicionales',
        'filter_metadata'
    ];

    protected $casts = [
        'campos_adicionales' => 'array',
        'filter_metadata' => 'array'
    ];

    // Estados posibles
    const STATUS_EN_COLA = 'en_cola';
    const STATUS_ENVIADO = 'enviado';
    const STATUS_ENTREGADO = 'entregado';
    const STATUS_LEIDO = 'leido';
    const STATUS_FALLIDO = 'fallido';
    const STATUS_DIFERIDO = 'diferido';

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
