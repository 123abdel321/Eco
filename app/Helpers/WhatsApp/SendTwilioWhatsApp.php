<?php

namespace App\Helpers\WhatsApp;

class SendTwilioWhatsApp extends AbstractTwilioWhatsAppSender
{
    private $to;
    private $contentSid;
    private $parameters;
    private $from;
    private $credenciales; // ✅ Nuevo

    public function __construct(String $contentSid, String $to, Array $parameters = [], ?array $credenciales = null)
    {
        $this->contentSid = $contentSid;
        $this->to = $to;
        $this->parameters = $parameters;
        $this->credenciales = $credenciales; // ✅ Nuevo
        
        // ✅ Si hay credenciales, usar el 'from' de ahí, sino del config
        $this->from = $credenciales['from'] ?? config('services.twilio.phone_number');
    }

    public function getContentSid(): string
    {
        return $this->contentSid;
    }

    public function getParameters(): array
    {
        return $this->parameters;
    }

    public function getTo(): string
    {
        return "whatsapp:+" . ltrim($this->to, '+');
    }

    public function getFrom(): string
    {
        return 'whatsapp:' . $this->from;
    }

    public function getMessagingServiceSid(): string
    {
        return $this->messagingServiceSid ?? '';
    }

    /**
     * ✅ Sobrescribir método para retornar credenciales
     */
    public function getCredenciales(): ?array
    {
        return $this->credenciales;
    }
}