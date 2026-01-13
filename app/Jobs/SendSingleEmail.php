<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Bus\Batchable;
use Illuminate\Mail\Mailable;
use App\Helpers\RateLimitingHelper;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Config;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Symfony\Component\Mailer\SentMessage;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
//MODELS
use App\Models\CredencialEnvio;
use App\Models\Sistema\EnvioEmail;
use App\Models\Sistema\ConfiguracionEnvio;
//MAIL
use App\Mail\RawHtmlMailable;

class SendSingleEmail implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;
    public $timeout = 300;
    public $maxExceptions = 1;

    public function __construct(
        public string $aplicacion,
        public string $email,
        public string $asunto,
        public string $from_name,
        public string $html,
        public array $archivos = [],
        public array $metadata = [],
        public int $envioEmailId,
        public ?int $userId = null
    ) {}

    public function handle()
    {
        // 1: Obtener la configuración del límite
        $config = ConfiguracionEnvio::porTipo(ConfiguracionEnvio::TIPO_EMAIL)
            ->activo()
            ->first();

        // Manejo de configuración faltante antes del try/catch
        if (!$config) {
            Log::warning('Configuración de email no encontrada o inactiva. Deteniendo Job.', [
                'envio_id' => $this->envioEmailId
            ]);
            $this->actualizarEstadoEnvio(EnvioEmail::STATUS_FALLIDO, 'Configuración global de envío inactiva.');
            return;
        }
        
        try {
            // 1. Verificar rate limiting GLOBAL para Email
            if (!RateLimitingHelper::puedeEnviar(
                'email', 
                $config->limite_por_minuto, 
                $config->limite_por_hora, 
                $config->limite_por_dia
            )) {
                Log::warning('Límite de rate de Email alcanzado. Reintentando Job en 60 segundos.', [
                    'envio_id' => $this->envioEmailId
                ]);
                $this->release(10);
                return;
            }

            // 2. Obtener el registro
            $envioEmail = EnvioEmail::find($this->envioEmailId);
            if (!$envioEmail) {
                Log::error('EnvioEmail no encontrado', [
                    'id' => $this->envioEmailId
                ]);
                return;
            }

            // 3. Registrar el envío (usando el Helper, que utiliza Cache)
            RateLimitingHelper::registrarEnvio('email');

            // 4. Obtener credenciales del usuario y configurar el driver
            $driver = $this->configurarDriverDeEnvio();

            $finalConfig = Config::get("mail.mailers.{$driver}");

            $fromAddress = $finalConfig['from']['address'] ?? 
                $finalConfig['address'] ?? // Fallback: A veces la dirección queda fuera del array 'from'
                'Configuración de remitente NO ENCONTRADA';

            $fromName = $finalConfig['from']['name'] ?? 'N/A';
            if ($this->from_name) {
                $fromName = $this->from_name;
            }
            
            // 5. Crear la clase Mailable ANÓNIMA
            $email = new class($this->aplicacion, $this->email, $this->asunto, $this->html, $this->archivos) extends Mailable {
                
                private $emailAsunto;
                private $emailHtml;
                private $emailArchivos;

                public function __construct(string $aplicacion, string $email, string $asunto, string $html, array $archivos)
                {
                    // Almacenamos los datos necesarios para el método build()
                    $this->emailAsunto = $asunto;
                    $this->emailHtml = $html;
                    $this->emailArchivos = $archivos;
                }

                public function build()
                {
                    $this->subject($this->emailAsunto)
                         ->html($this->emailHtml); // Usamos el método html() de Mailable

                    // Adjuntar archivos si existen
                    foreach ($this->emailArchivos as $archivo) {
                        // Decodificamos el contenido base64 antes de adjuntar
                        $this->attachData(
                            base64_decode($archivo['contenido']), 
                            $archivo['nombre'], 
                            ['mime' => $archivo['mime'] ?? 'application/octet-stream']
                        );
                    }

                    return $this;
                }
            };
            
            // CRÍTICO: Forzar el remitente en el Mailable para asegurar que use la credencial.
            if ($fromAddress !== 'Configuración de remitente NO ENCONTRADA') {
                $email->from($fromAddress, $fromName);
            }

            // 6. Enviar con el driver configurado
            $response = Mail::mailer($driver)->to($this->email)->send($email);
            
            // 7. Actualizar estado y obtener Message ID (AJUSTE CRÍTICO DE TIPO APLICADO AQUÍ)
            $messageId = null;
            
            if ($response instanceof \Illuminate\Mail\SentMessage) {
                // Caso 1: Laravel devuelve el objeto wrapper esperado
                $messageId = $response->getSymfonySentMessage()?->getMessageId();
            } elseif ($response instanceof SentMessage) {
                 // Caso 2: Laravel devuelve directamente el objeto de Symfony
                $messageId = $response->getMessageId();
            } elseif ($response === 1 || $response === 0 || $response === true) {
                // Generamos un ID local temporal para el registro.
                $messageId = 'SUCCESS_SENT_LOCAL-' . uniqid(); 
            } else {
                // Caso 4: Tipo de respuesta inesperado
                Log::warning('Respuesta de Mailer: Tipo de respuesta inesperado.', ['response_type' => gettype($response)]);
                $messageId = 'UNKNOWN_RESPONSE-' . uniqid();
            }
            
            // 8. Actualizar el registro
            $envioEmail->update([
                'status' => EnvioEmail::STATUS_ENVIADO,
                // Usamos sg_message_id para guardar el ID de seguimiento (real o temporal)
                'message_id' => $messageId, 
                'campos_adicionales' => array_merge(
                    $envioEmail->campos_adicionales ?? [],
                    ['enviado_en' => now()->toISOString()],
                    ['driver_usado' => $driver], 
                    ['credencial_id' => $envioEmail->campos_adicionales['credencial_id'] ?? null],
                )
            ]);

        } catch (\Throwable $exception) {
            Log::error('SendSingleEmail falló', [
                'aplicacion' => $this->aplicacion,
                'email' => $this->email,
                'envio_id' => $this->envioEmailId,
                'error' => $exception->getMessage(),
            ]);
            
            $this->registrarError($exception);
            
            throw $exception;
        }
    }

    /**
     * Obtiene y configura un driver de mail dinámico basado en las credenciales del usuario.
     * Retorna el nombre del driver (e.g., 'user_mailgun', 'user_smtp') o 'default' si usa el sistema.
     */
    private function configurarDriverDeEnvio(): string
    {
        // 1. Si no hay userId, usar el driver por defecto del sistema
        if (!$this->userId) {
            return Config::get('mail.default', 'smtp');
        }

        // 2. Buscar credenciales de email del usuario
        $credencial = CredencialEnvio::porUsuario($this->userId)
            ->porTipo(CredencialEnvio::TIPO_EMAIL)
            ->activas()
            ->predeterminadas()
            ->first();

        // 3. Si no hay credenciales, usar el driver por defecto
        if (!$credencial) {
            Log::info('No se encontraron credenciales de Email para el usuario. Usando driver por defecto.', [
                'user_id' => $this->userId,
                'envio_id' => $this->envioEmailId
            ]);
            return Config::get('mail.default', 'smtp');
        }

        $creds = $credencial->credenciales_desencriptadas;

        // 1. Obtener el nombre del driver (e.g., 'smtp') y asegurar que no sea vacío.
        $driver = (isset($creds['driver']) && !empty($creds['driver'])) ? $creds['driver'] : 'smtp';
        
        // 2. CRÍTICO: Mapear la clave 'driver' a 'transport', ya que Laravel 9+ (Symfony Mailer)
        // espera la clave 'transport' para definir el mecanismo de envío.
        $creds['transport'] = $creds['transport'] ?? $creds['driver'] ?? 'smtp';
        
        $creds['from'] = [
            'address' => $creds['address'] ?? null,
            'name' => $creds['name'] ?? null
        ];
        
        // 3. Opcional: Eliminar la clave 'driver' para evitar duplicidad o confusión en la configuración.
        if (isset($creds['driver'])) {
            unset($creds['driver']);
        }
        
        $driverName = "user_{$this->userId}_{$driver}";

        // 4. Configurar dinámicamente el driver
        Config::set("mail.mailers.{$driverName}", $creds);

        // 5. Actualizar el registro de EnvioEmail para guardar el ID de la credencial usada
        $envioEmail = EnvioEmail::find($this->envioEmailId);
        if ($envioEmail) {
            $envioEmail->campos_adicionales = array_merge(
                $envioEmail->campos_adicionales ?? [],
                ['credencial_id' => $credencial->id]
            );
            $envioEmail->save();
        }
        
        return $driverName;
    }

    private function actualizarEstadoEnvio(string $status, ?string $mensaje = null): void
    {
        $envioEmail = EnvioEmail::find($this->envioEmailId);
        if ($envioEmail) {
            $updateData = ['status' => $status];
            if ($mensaje) {
                $updateData['campos_adicionales'] = array_merge(
                    $envioEmail->campos_adicionales ?? [],
                    ['mensaje_sistema' => $mensaje]
                );
            }
            $envioEmail->update($updateData);
        }
    }

    private function puedeEnviarEmail(): bool
    {
        $config = ConfiguracionEnvio::porTipo(ConfiguracionEnvio::TIPO_EMAIL)
            ->activo()
            ->first();

        if (!$config) {
            Log::warning('Configuración de email no encontrada o inactiva');
            return false;
        }

        // ✅ USANDO EL HELPER
        return RateLimitingHelper::puedeEnviar(
            'email', 
            $config->limite_por_minuto, 
            $config->limite_por_hora, 
            $config->limite_por_dia
        );
    }

    private function registrarEnvio(): void
    {
        // ✅ USANDO EL HELPER
        RateLimitingHelper::registrarEnvio('email');
    }

    private function registrarError(\Throwable $exception): void
    {
        // Actualizar estado a fallido
        $envioEmail = EnvioEmail::find($this->envioEmailId);
        if ($envioEmail) {
            $envioEmail->update([
                'status' => EnvioEmail::STATUS_FALLIDO,
                'campos_adicionales' => array_merge(
                    $envioEmail->campos_adicionales ?? [],
                    ['error_final' => $exception->getMessage()],
                    ['trace' => substr($exception->getTraceAsString(), 0, 1000)]
                )
            ]);
        }
    }

    public function failed(\Throwable $exception)
    {
        Log::error('SendSingleEmail falló definitivamente', [
            'aplicacion' => $this->aplicacion,
            'email' => $this->email,
            'envio_id' => $this->envioEmailId,
            'error_final' => $exception->getMessage(),
        ]);

        $this->registrarError($exception);
    }
}