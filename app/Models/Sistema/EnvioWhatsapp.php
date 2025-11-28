<?php

namespace App\Models\Sistema;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
//MODELS
use App\Models\User;

class EnvioWhatsapp extends Model
{
    use HasFactory;

    protected $connection = 'eco';

    protected $table = "envios_whatsapp";

    protected $fillable = [
        'user_id',
        'plantilla_id',
        'message_id',
        'phone',
        'contexto',
        'status',
        'campos_adicionales',
    ];

    protected $casts = [
        'campos_adicionales' => 'array',
    ];

    // Estados posibles
    const STATUS_EN_COLA = 'en_cola';
    const STATUS_ENVIADO = 'enviado';
    const STATUS_ENTREGADO = 'entregado';
    const STATUS_LEIDO = 'leido';
    const STATUS_FALLIDO = 'fallido';

    public function user()
    {
        return $this->setConnection('clientes')->belongsTo(User::class, 'user_id');
    }

    public function detalles()
    {
        return $this->hasMany(EnvioWhatsappDetalle::class, 'id_whatsapp');
    }
}
