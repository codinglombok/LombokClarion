<?php

declare(strict_types=1);

namespace LombokClarion\Mail;

use LombokClarion\Mail\Transport\Transport;
use LombokClarion\View\ViewEngine;

/**
 * Renders Mailable objects through the view engine and dispatches them
 * through the configured transport.
 *
 * Injected as a singleton; the transport decides HOW mail is delivered
 * (SMTP, sendmail, log, array). The Mailer itself is transport-agnostic.
 */
final class Mailer
{
    public function __construct(
        private readonly Transport $transport,
        private readonly ?ViewEngine $viewEngine = null,
        private readonly ?Address $defaultFrom = null,
    ) {
    }

    /**
     * Send a Mailable.
     */
    public function send(Mailable $mailable): void
    {
        $envelope = $mailable->envelope();
        $content = $mailable->content();

        // Apply default sender if envelope has none
        if ($envelope->from === null && $this->defaultFrom !== null) {
            $envelope = $envelope->from($this->defaultFrom->email, $this->defaultFrom->name);
        }

        $htmlBody = $this->renderHtml($content);
        $textBody = $content->text;

        $this->transport->send($envelope, $htmlBody, $textBody, $mailable->attachments());
    }

    /**
     * Send a raw message without a Mailable class.
     */
    public function raw(Envelope $envelope, string $htmlBody, ?string $textBody = null): void
    {
        if ($envelope->from === null && $this->defaultFrom !== null) {
            $envelope = $envelope->from($this->defaultFrom->email, $this->defaultFrom->name);
        }

        $this->transport->send($envelope, $htmlBody, $textBody);
    }

    private function renderHtml(Content $content): string
    {
        if ($content->html !== null) {
            return $content->html;
        }

        if ($content->view !== null) {
            if ($this->viewEngine === null) {
                throw new Exceptions\MailException(
                    'Content uses a view template but no ViewEngine was provided to the Mailer.'
                );
            }

            return $this->viewEngine->render($content->view, $content->data);
        }

        // Text-only: wrap in minimal HTML
        return '<html><body><pre>' . htmlspecialchars($content->text ?? '', ENT_QUOTES, 'UTF-8') . '</pre></body></html>';
    }
}
