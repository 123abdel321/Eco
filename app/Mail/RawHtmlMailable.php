<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Contracts\Queue\ShouldQueue; // Se mantiene por si es necesario

class RawHtmlMailable extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public $mailHtml;
    public $mailAsunto;
    public $mailArchivos;

    /**
     * Create a new message instance.
     */
    public function __construct(string $asunto, string $html, array $archivos = [])
    {
        $this->mailAsunto = $asunto;
        $this->mailHtml = $html;
        $this->mailArchivos = $archivos;
    }

    public function build()
    {
        $mail = $this->subject($this->mailAsunto)->html($this->mailHtml);

        foreach ($this->mailArchivos as $archivo) {
            if (isset($archivo['contenido'], $archivo['nombre'])) {
                $mail->attachData(
                    base64_decode($archivo['contenido']),
                    $archivo['nombre'],
                    ['mime' => $archivo['mime'] ?? 'application/octet-stream']
                );
            }
        }
        
        return $mail;
    }

    /**
     * Get the attachments for the message.
     *
     * @return array<int, \Illuminate\Mail\Mailables\Attachment>
     */
    public function attachments(): array
    {
        return [];
    }
}