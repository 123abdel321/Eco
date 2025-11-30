<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Bus\Batchable;
use Illuminate\Support\Facades\Log;
use App\Helpers\RateLimitingHelper;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use App\Helpers\WhatsApp\SendTwilioWhatsApp;
//MODELS
use App\Models\CredencialEnvio;
use App\Models\ConfiguracionEnvio;
use App\Models\Sistema\EnvioWhatsapp;
use App\Models\Sistema\EnvioWhatsappDetalle;

class SendSingleWhatsapp implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;
    public $timeout = 60;
    public $maxExceptions = 1;

    public function __construct(
        public string $to,
        public string $contentSid,
        public array $parameters,
        public int $envioWhatsappId,
        public ?int $userId = null // Nuevo parámetro
    ) {}

    public function handle()
    {
        // 1: Obtener la configuración del límite
        $config = ConfiguracionEnvio::porTipo(ConfiguracionEnvio::TIPO_WHATSAPP)
            ->activo()
            ->first();

        if (!$config) {
            Log::warning('Configuración de WhatsApp no encontrada o inactiva. Deteniendo Job.', [
                'envio_id' => $this->envioWhatsappId
            ]);
            return;
        }

        try {
            // 1. Verificar rate limiting GLOBAL para WhatsApp
            if (!RateLimitingHelper::puedeEnviar(
                'whatsapp',
                $config->limite_por_minuto,
                $config->limite_por_hora,
                $config->limite_por_dia
            )) {
                Log::warning('Límite de rate de WhatsApp alcanzado. Reintentando envio en 10 segundos.', [
                    'envio_id' => $this->envioWhatsappId
                ]);
                $this->release(10);
                return;
            }

            // 2. Obtener el registro
            $envioWhatsapp = EnvioWhatsapp::find($this->envioWhatsappId);
            if (!$envioWhatsapp) {
                Log::error('EnvioWhatsapp no encontrado', [
                    'id' => $this->envioWhatsappId
                ]);
                return;
            }

            // 3. Registrar el envío
            RateLimitingHelper::registrarEnvio('whatsapp');

            // 4. Obtener credenciales (del usuario o null para usar sistema)
            $credenciales = $this->obtenerCredenciales();

            // 5. Enviar WhatsApp (con o sin credenciales)
            $whatsapp = new SendTwilioWhatsApp(
                $this->contentSid,
                $this->to,
                $this->parameters,
                $credenciales // null o array con credenciales
            );

            $result = $whatsapp->send();

            Log::error('SendSingleWhatsapp response', [
                'response' => json_encode($result->response)
            ]);

            // 6. Actualizar el registro
            if ($result->status == 200 && isset($result->response->sid)) {
                $envioWhatsapp->status = 'enviado';
                // Registrar detalle exitoso
                EnvioWhatsappDetalle::create([
                    'id_whatsapp' => $envioWhatsapp->id,
                    'phone' => $envioWhatsapp->phone,
                    'event' => 'enviado',
                    'message_id' => $result->response->sid,
                    'response' => json_encode($result->response),
                    'timestamp' => now()->timestamp,
                ]);
            } else {
                $envioWhatsapp->status = 'fallido';
                // Registrar detalle de error
                EnvioWhatsappDetalle::create([
                    'id_whatsapp' => $envioWhatsapp->id,
                    'phone' => $envioWhatsapp->phone,
                    'event' => 'fallido',
                    'message_id' => $result->response->sid ?? null,
                    'response' => json_encode($result),
                    'timestamp' => now()->timestamp,
                ]);
            }

            $envioWhatsapp->message_id = $result->response->sid ?? null;
            $envioWhatsapp->plantilla_id = $this->contentSid;
            $envioWhatsapp->save();

        } catch (\Throwable $exception) {
            Log::error('SendSingleWhatsapp falló', [
                'whatsapp' => $this->to,
                'envio_id' => $this->envioWhatsappId,
                'error' => $exception->getMessage(),
            ]);
            
            $this->registrarError($exception);
            throw $exception;
        }
    }

    /**
     * Obtener credenciales del usuario o retornar null para usar sistema
     */
    private function obtenerCredenciales(): ?array
    {
        // Si no hay userId, usar credenciales del sistema
        if (!$this->userId) {
            return null;
        }

        // Buscar credenciales del usuario
        $credencial = CredencialEnvio::porUsuario($this->userId)
            ->porTipo(CredencialEnvio::TIPO_WHATSAPP)
            ->activas()
            ->predeterminadas()
            ->first();

        // Si no tiene credenciales, usar del sistema
        if (!$credencial) {
            return null;
        }

        // Obtener credenciales desencriptadas
        $creds = $credencial->credenciales_desencriptadas;

        Log::info('Usando credenciales del usuario', [
            'user_id' => $this->userId,
            'credencial_id' => $credencial->id,
            'envio_id' => $this->envioWhatsappId
        ]);

        return [
            'account_sid' => $creds['account_sid'] ?? null,
            'auth_token' => $creds['auth_token'] ?? null,
            'from' => $creds['from'] ?? null,
        ];
    }

    private function registrarError(\Throwable $exception): void
    {
        $envioWhatsapp = EnvioWhatsapp::find($this->envioWhatsappId);
        
        if ($envioWhatsapp) {
            // Crear el registro de detalle del error
            EnvioWhatsappDetalle::create([
                'id_whatsapp' => $envioWhatsapp->id,
                'phone' => $envioWhatsapp->phone,
                'event' => 'JOB_FAILURE',
                'error_code' => $exception->getCode(),
                'error_message' => $exception->getMessage(),
                'response' => json_encode([
                    'file' => $exception->getFile(),
                    'line' => $exception->getLine(),
                    'trace' => $exception->getTraceAsString(),
                ]),
                'timestamp' => now()->timestamp,
            ]);
            
            // Marcar el envío principal como fallido
            $envioWhatsapp->status = EnvioWhatsapp::STATUS_FALLIDO;
            $envioWhatsapp->save();
        }
    }

    public function failed(\Throwable $exception)
    {
        Log::error('SendSingleWhatsapp falló definitivamente', [
            'whatsapp' => $this->to,
            'envio_id' => $this->envioWhatsappId,
            'error' => $exception->getMessage(),
        ]);
        
        $this->registrarError($exception);
    }
}