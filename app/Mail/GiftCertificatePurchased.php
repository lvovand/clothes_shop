<?php

namespace App\Mail;

use App\Models\GiftCertificate;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class GiftCertificatePurchased extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public GiftCertificate $certificate)
    {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Ваш подарочный сертификат ROPA WORLD',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.gift-certificate-purchased',
        );
    }
}
