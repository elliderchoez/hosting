<?php

namespace App\Mail;

use App\Models\Recruiter;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PartnerRejectedMail extends Mailable
{
    use Queueable, SerializesModels;

    public Recruiter $partner;
    public string $reason;
    public string $registerUrl;

    /**
     * Create a new message instance.
     */
    public function __construct(Recruiter $partner, string $reason)
    {
        $this->partner = $partner;
        $this->reason = $reason;
        $this->registerUrl = url('/register/partner');
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        $tipo = $this->partner->account_type === 'company' ? 'Empresa' : 'Reclutador';
        return new Envelope(
            subject: "Resolución de Solicitud de {$tipo} — Nexus Academic",
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'emails.partner-rejected',
        );
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
