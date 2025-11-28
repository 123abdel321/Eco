<?php

namespace App\Services;

//MODELS
use App\Models\User;
use App\Models\CredencialEnvio;

class CredencialService
{
    protected const MASTER_USER_ID = 1;

    /**
     * Obtener credenciales de Twilio (del usuario o del sistema)
     */
    public static function obtenerCredencialesTwilio(?int $userId, string $tipo = 'whatsapp'): array
    {
        // Intentar obtener credenciales del usuario
        $credencial = CredencialEnvio::obtenerCredencial($userId, $tipo);

        if ($credencial) {
            return $credencial->obtenerCredencialesTwilio();
        }

        // Usar credenciales del .env como fallback
        return [
            'account_sid' => config('services.twilio.account_sid'),
            'auth_token' => config('services.twilio.auth_token'),
            'from' => config('services.twilio.whatsapp_from'),
        ];
    }

    /**
     * Crear o actualizar credenciales para un usuario
     */
    public static function guardarCredenciales(
        int $userId,
        string $tipo,
        string $proveedor,
        array $credenciales,
        bool $esPredeterminado = true
    ): CredencialEnvio {
        // Buscar si ya existe
        $credencialExistente = CredencialEnvio::porUsuario($userId)
            ->porTipo($tipo)
            ->where('proveedor', $proveedor)
            ->first();

        if ($credencialExistente) {
            // Actualizar
            $credencialExistente->update([
                'credenciales' => $credenciales,
                'activo' => true,
                'es_predeterminado' => $esPredeterminado,
            ]);

            if ($esPredeterminado) {
                $credencialExistente->marcarComoPredeterminada();
            }

            return $credencialExistente;
        }

        // Crear nueva
        $nuevaCredencial = CredencialEnvio::create([
            'user_id' => $userId,
            'tipo' => $tipo,
            'proveedor' => $proveedor,
            'credenciales' => $credenciales,
            'activo' => true,
            'es_predeterminado' => $esPredeterminado,
            'estado_verificacion' => CredencialEnvio::PENDIENTE,
        ]);

        if ($esPredeterminado) {
            $nuevaCredencial->marcarComoPredeterminada();
        }

        return $nuevaCredencial;
    }

    /**
     * Validar estructura de credenciales según tipo
     */
    public static function validarEstructuraCredenciales(string $tipo, string $proveedor, array $credenciales): array
    {
        $errores = [];

        switch ($tipo) {
            case CredencialEnvio::TIPO_WHATSAPP:
            case CredencialEnvio::TIPO_SMS:
                if ($proveedor === CredencialEnvio::PROVEEDOR_TWILIO) {
                    if (empty($credenciales['account_sid'])) {
                        $errores[] = 'El Account SID es requerido';
                    }
                    if (empty($credenciales['auth_token'])) {
                        $errores[] = 'El Auth Token es requerido';
                    }
                    if (empty($credenciales['from'])) {
                        $errores[] = 'El número de origen es requerido';
                    }
                }
                break;

            case CredencialEnvio::TIPO_EMAIL:
                if ($proveedor === CredencialEnvio::PROVEEDOR_SMTP) {
                    if (empty($credenciales['host'])) {
                        $errores[] = 'El host SMTP es requerido';
                    }
                    if (empty($credenciales['port'])) {
                        $errores[] = 'El puerto SMTP es requerido';
                    }
                    if (empty($credenciales['username'])) {
                        $errores[] = 'El usuario SMTP es requerido';
                    }
                    if (empty($credenciales['password'])) {
                        $errores[] = 'La contraseña SMTP es requerida';
                    }
                }
                break;
        }

        return $errores;
    }

    /**
     * Registra las credenciales de envío por defecto para un nuevo usuario,
     * duplicándolas del usuario maestro (ID 1).
     * @param User $newUser El usuario recién creado.
     */
    public function createDefaultCredentials(User $newUser): void
    {
        // 1. Buscar las credenciales predeterminadas del usuario maestro (ID 1)
        $masterCredentials = CredencialEnvio::where('user_id', self::MASTER_USER_ID)
            ->where('es_predeterminado', true) 
            ->get();

        if ($masterCredentials->isEmpty()) {
            // No hay credenciales maestras para duplicar, se omite el proceso.
            return;
        }

        // 2. Duplicar las credenciales para el nuevo usuario
        foreach ($masterCredentials as $masterCredencial) {
            
            // Usamos replicate() para obtener una copia de la credencial sin los IDs de DB ni timestamps.
            $newCredencial = $masterCredencial->replicate();

            // 3. Modificamos los campos para el nuevo usuario
            $newCredencial->user_id = $newUser->id;

            
            // Resetear el estado de verificación, ya que las credenciales clonadas no han sido verificadas por el nuevo usuario.
            // Asumo que CredencialEnvio::PENDIENTE está definido en tu modelo.
            $newCredencial->estado_verificacion = CredencialEnvio::VERIFICADO; 
            $newCredencial->mensaje_verificacion = 'Copia de credenciales maestras. Requiere verificación inicial.';
            // $newCredencial->ultima_verificacion = null;
            
            // 4. Guardar la nueva credencial
            $newCredencial->save();
        }
    }
}