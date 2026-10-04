<?php

namespace App\Modules\Notifications\Mail;

use App\Modules\Instance\InstanceConfig;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Queue\SerializesModels;

class TemplatedMail extends Mailable
{
    use Queueable, SerializesModels;

    /** @param  array<string, string>  $extraHeaders */
    public function __construct(
        public string $subjectLine,
        public string $bodyHtml,
        public ?string $actionUrl = null,
        public ?string $actionText = null,
        public ?string $footerHtml = null,
        public array $extraHeaders = [],
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectLine);
    }

    public function headers(): Headers
    {
        return new Headers(text: $this->extraHeaders);
    }

    public function content(): Content
    {
        return new Content(view: 'emails.layout', with: [
            'body' => $this->bodyHtml,
            'actionUrl' => $this->actionUrl,
            'actionText' => $this->actionText,
            'footerHtml' => $this->footerHtml,
            'brand' => app(InstanceConfig::class)->publicConfig(),
        ]);
    }
}
