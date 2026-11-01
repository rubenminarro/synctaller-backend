<?php

namespace App\Mail;

use App\Services\BrandingService;
use Illuminate\Mail\Attachment;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Symfony\Component\Mime\Email;

/**
 * Email de un aviso al cliente. El contenido lo arma
 * ClientNotificationService::content() (independiente del canal).
 */
class ClientNotificationMail extends Mailable
{
    /**
     * @param array{subject: string, greeting: string, lines: string[], rows: array<int, array<string, string>>, attachments?: array<int, array{filename: string, content: string}>} $content
     */
    public function __construct(
        public readonly array $content
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            // Explícito: el mailer guarda el remitente global al crearse, y en un worker no vería un cambio.
            from: new Address(config('mail.from.address'), config('mail.from.name')),
            subject: $this->content['subject'],
            // Logo del sistema para la cabecera (inline, no como adjunto).
            using: [fn (Email $message) => app(BrandingService::class)->embedLogo($message)],
        );
    }

    /**
     * Adjuntos del contenido (p. ej. el PDF del presupuesto).
     */
    public function attachments(): array
    {
        return array_map(
            fn (array $file) => Attachment::fromData(fn () => $file['content'], $file['filename'])->withMime('application/pdf'),
            $this->content['attachments'] ?? []
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.client-notification',
            with: [
                'greeting' => $this->content['greeting'],
                'lines' => $this->content['lines'],
                'rows' => $this->content['rows'],
            ],
        );
    }
}
