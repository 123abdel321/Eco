<?php

namespace App\Helpers\WhatsApp;

use Twilio\Rest\Client;
use Illuminate\Support\Facades\Log;

abstract class AbstractTwilioWhatsAppSender
{
    public abstract function getContentSid(): string;
    public abstract function getParameters(): array;
    public abstract function getTo(): string;
    public abstract function getFrom(): string;
    public abstract function getMessagingServiceSid(): string;

    /**
     * Agregar método para obtener credenciales (opcional)
     */
    public function getCredenciales(): ?array
    {
        return null; // Por defecto null, se puede sobrescribir
    }

    public function send($id_empresa = null)
    {
        // Obtener credenciales (si existen) o usar del config
        $credenciales = $this->getCredenciales();
        
        $sid = $credenciales['account_sid'] ?? config('services.twilio.account_sid');
        $token = $credenciales['auth_token'] ?? config('services.twilio.auth_token');

        $twilio = new Client($sid, $token);

        Log::error('SendSingleWhatsapp response', [
            "from" => $this->getFrom(),
            "contentSid" => $this->getContentSid(),
            "contentVariables" => json_encode($this->getParameters())
        ]);
        
        try {
            
            $message = $twilio->messages->create(
                $this->getTo(),
                [
                    "from" => $this->getFrom(),
                    "contentSid" => $this->getContentSid(),
                    "contentVariables" => json_encode($this->getParameters())
                ]
            );

            return (object)[
                "status" => 200,
                "response" => (object)[
                    'sid' => $message->sid,
                    'status' => $message->status,
                    'body' => $message->body,
                    'to' => $message->to,
                    'from' => $message->from,
                    'date_created' => $message->dateCreated->format('Y-m-d H:i:s')
                ],
            ];
        } catch (\Exception $e) {
            return (object)[ // Cambiar a object para consistencia
                "status" => 500,
                "response" => (object)[
                    'error' => $e->getMessage(),
                    'code' => $e->getCode()
                ],
            ];
        }
    }
}