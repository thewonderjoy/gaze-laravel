<?php

declare(strict_types=1);

namespace TheWonderJoy\Gaze\Laravel\Tests\Fixtures\App;

use Illuminate\Mail\Attachment;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class WelcomeMail extends Mailable
{
    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address('shop@example.com', 'Shop'),
            to: [new Address('ada@example.com', 'Ada Lovelace')],
            cc: ['cc@example.com'],
            bcc: ['audit@example.com'],
            replyTo: ['help@example.com'],
            subject: 'Welcome aboard',
        );
    }

    public function content(): Content
    {
        return new Content(htmlString: '<h1>Welcome, Ada</h1><p>Glad you are here.</p>');
    }

    public function attachments(): array
    {
        return [Attachment::fromData(fn () => 'id,total' . "\n" . '1,9.99', 'invoice.csv')->withMime('text/csv')];
    }
}
