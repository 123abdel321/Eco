<?php

namespace App\Models\Sistema;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ConfiguracionEnvio extends Model
{
    use HasFactory;

    protected $connection = 'eco';

    protected $table = "configuracion_envios";

    protected $fillable = [
        'tipo',
        'limite_por_minuto',
        'limite_por_hora',
        'limite_por_dia',
        'activo',
        'configuracion',
    ];

    protected $casts = [
        'configuracion' => 'array',
        'activo' => 'boolean',
        'limite_por_minuto' => 'integer',
        'limite_por_hora' => 'integer',
        'limite_por_dia' => 'integer',
    ];

    const TIPO_EMAIL = 'email';
    const TIPO_WHATSAPP = 'whatsapp';

    // 🔥 AGREGAR ESTOS MÉTODOS QUE FALTAN:

    /**
     * Scope para filtrar por tipo
     */
    public function scopePorTipo($query, $tipo)
    {
        return $query->where('tipo', $tipo);
    }

    /**
     * Scope para configuraciones activas
     */
    public function scopeActivo($query)
    {
        return $query->where('activo', true);
    }

    /**
     * Obtener configuración por tipo (método estático útil)
     */
    public static function obtenerPorTipo($tipo)
    {
        return static::porTipo($tipo)->activo()->first();
    }
}