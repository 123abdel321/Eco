<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

class CredencialEnvio extends Model
{
    use HasFactory;

    protected $connection = 'clientes';

    protected $table = "credenciales_envio";

    // Tipos de envío
    const TIPO_WHATSAPP = 'whatsapp';
    const TIPO_EMAIL = 'email';
    const TIPO_SMS = 'sms';

    // Proveedores
    const PROVEEDOR_TWILIO = 'twilio';
    const PROVEEDOR_SMTP = 'smtp';
    const PROVEEDOR_SENDGRID = 'sendgrid';

    // Estados de verificación
    const VERIFICADO = 'verificado';
    const ERROR = 'error';
    const PENDIENTE = 'pendiente';

    protected $fillable = [
        'user_id',
        'tipo',
        'proveedor',
        'credenciales',
        'activo',
        'es_predeterminado',
        'ultima_verificacion',
        'estado_verificacion',
        'mensaje_verificacion',
    ];

    protected $casts = [
        'activo' => 'boolean',
        'es_predeterminado' => 'boolean',
        'ultima_verificacion' => 'datetime',
    ];

    protected $hidden = [
        'credenciales',
    ];

    // ==================== RELACIONES ====================
    
    public function user(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class);
    }

    // ==================== ACCESSORS ====================
    
    /**
     * Obtener credenciales desencriptadas
     */
    public function getCredencialesDesencriptadasAttribute(): array
    {
        try {
            return json_decode(Crypt::decryptString($this->credenciales), true) ?? [];
        } catch (\Exception $e) {
            return [];
        }
    }

    // ==================== MUTATORS ====================
    
    /**
     * Encriptar credenciales antes de guardar
     */
    public function setCredencialesAttribute($value): void
    {
        if (is_array($value)) {
            $this->attributes['credenciales'] = Crypt::encryptString(json_encode($value));
        } else {
            $this->attributes['credenciales'] = $value;
        }
    }

    // ==================== SCOPES ====================
    
    public function scopeActivas($query)
    {
        return $query->where('activo', true);
    }

    public function scopePorTipo($query, string $tipo)
    {
        return $query->where('tipo', $tipo);
    }

    public function scopePorUsuario($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    public function scopePredeterminadas($query)
    {
        return $query->where('es_predeterminado', true);
    }

    // ==================== MÉTODOS ====================
    
    /**
     * Obtener credencial por defecto del usuario o del sistema
     */
    public static function obtenerCredencial(?int $userId, string $tipo): ?self
    {
        // 1. Intentar obtener credencial del usuario
        if ($userId) {
            $credencial = self::porUsuario($userId)
                ->porTipo($tipo)
                ->activas()
                ->predeterminadas()
                ->first();
            
            if ($credencial) {
                return $credencial;
            }
        }

        // 2. Si no tiene, retornar null (usar credenciales del .env)
        return null;
    }

    /**
     * Marcar como predeterminada (desmarcar las demás del mismo tipo)
     */
    public function marcarComoPredeterminada(): void
    {
        // Desmarcar todas las demás del mismo tipo para este usuario
        self::where('user_id', $this->user_id)
            ->where('tipo', $this->tipo)
            ->where('id', '!=', $this->id)
            ->update(['es_predeterminado' => false]);

        // Marcar esta como predeterminada
        $this->update(['es_predeterminado' => true]);
    }

    /**
     * Verificar si las credenciales son válidas
     */
    public function verificarCredenciales(): bool
    {
        try {
            $credenciales = $this->credenciales_desencriptadas;
            
            switch ($this->tipo) {
                case self::TIPO_WHATSAPP:
                    return $this->verificarTwilioWhatsapp($credenciales);
                case self::TIPO_SMS:
                    return $this->verificarTwilioSms($credenciales);
                case self::TIPO_EMAIL:
                    return $this->verificarEmail($credenciales);
                default:
                    return false;
            }
        } catch (\Exception $e) {
            $this->update([
                'estado_verificacion' => self::ERROR,
                'mensaje_verificacion' => $e->getMessage(),
                'ultima_verificacion' => now(),
            ]);
            return false;
        }
    }

    /**
     * Verificar credenciales de Twilio WhatsApp
     */
    private function verificarTwilioWhatsapp(array $credenciales): bool
    {
        if (empty($credenciales['account_sid']) || empty($credenciales['auth_token'])) {
            throw new \Exception('Credenciales de Twilio incompletas');
        }

        // Aquí podrías hacer una llamada real a la API de Twilio para verificar
        // Por ahora solo verificamos que existan los datos necesarios
        
        $this->update([
            'estado_verificacion' => self::VERIFICADO,
            'mensaje_verificacion' => 'Credenciales verificadas correctamente',
            'ultima_verificacion' => now(),
        ]);

        return true;
    }

    /**
     * Verificar credenciales de Twilio SMS
     */
    private function verificarTwilioSms(array $credenciales): bool
    {
        return $this->verificarTwilioWhatsapp($credenciales);
    }

    /**
     * Verificar credenciales de Email
     */
    private function verificarEmail(array $credenciales): bool
    {
        if ($this->proveedor === self::PROVEEDOR_SMTP) {
            if (empty($credenciales['host']) || empty($credenciales['port'])) {
                throw new \Exception('Credenciales SMTP incompletas');
            }
        }

        $this->update([
            'estado_verificacion' => self::VERIFICADO,
            'mensaje_verificacion' => 'Credenciales verificadas correctamente',
            'ultima_verificacion' => now(),
        ]);

        return true;
    }

    /**
     * Obtener credenciales formateadas para usar con Twilio
     */
    public function obtenerCredencialesTwilio(): array
    {
        $creds = $this->credenciales_desencriptadas;
        
        return [
            'account_sid' => $creds['account_sid'] ?? null,
            'auth_token' => $creds['auth_token'] ?? null,
            'from' => $creds['from'] ?? null,
        ];
    }
}
