<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Email undangan. Hanya memuat nama sekolah, peran, dan tautan; tidak ada data siswa (ADR 0008).
 */
class InvitationMail extends Mailable
{
    /**
     * @param  list<string>  $roleNames
     */
    public function __construct(
        public readonly string $schoolName,
        public readonly array $roleNames,
        public readonly string $url,
        public readonly string $expiresAt,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Undangan bergabung dengan {$this->schoolName} di Sentra Absensi");
    }

    public function content(): Content
    {
        return new Content(text: 'mail.invitation');
    }
}
