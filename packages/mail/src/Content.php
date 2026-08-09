<?php

declare(strict_types=1);

namespace LombokClarion\Mail;

/**
 * Defines the body of a mail message. Either a view template (rendered by
 * ViewEngine) or raw text/html.
 */
final class Content
{
    /**
     * @param string|null $view   template name (e.g. 'emails.welcome')
     * @param string|null $html   raw HTML body (used when $view is null)
     * @param string|null $text   plain-text body (multipart alternative)
     * @param array<string, mixed> $data template variables
     */
    public function __construct(
        public readonly ?string $view = null,
        public readonly ?string $html = null,
        public readonly ?string $text = null,
        public readonly array $data = [],
    ) {
        if ($this->view === null && $this->html === null && $this->text === null) {
            throw new Exceptions\MailException(
                'Content requires at least one of: view, html, or text.'
            );
        }
    }
}
