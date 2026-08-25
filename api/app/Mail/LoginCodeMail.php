<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class LoginCodeMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $code,
        public string $name,
        public int $ttlMinutes,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            // Pinned explicitly: this is the address staff are told to trust, so
            // it must not drift with the global "from" used by other mail.
            from: new Address(
                config('mail.otp_from.address'),
                config('mail.otp_from.name'),
            ),
            subject: "{$this->code} is your ICT Inventory sign-in code",
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.login-code',
            with: [
                'code' => $this->code,
                'name' => $this->name,
                'ttlMinutes' => $this->ttlMinutes,
            ],
        );
    }
}
