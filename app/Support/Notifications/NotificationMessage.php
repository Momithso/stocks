<?php

namespace App\Support\Notifications;

use Closure;

/**
 * Content of a notification, from which the mail and the push message are made (see
 * App\Notifications\StocksNotification). A push carries only title, the short text and the address;
 * mails carry everything.
 */
class NotificationMessage
{
    /**
     * Attachments of the mail: file name => closure that returns the content (made when the mail
     * is built, so the queue does not carry pdfs).
     *
     * @var array<string, Closure(): string>
     */
    public array $attachments = [];

    /**
     * @param  string  $title  subject of the mail, title of the push
     * @param  list<string>  $lines  paragraphs of the mail
     * @param  string|null  $url  address the button and a tap on the push open
     * @param  string|null  $action  text of the button
     * @param  string|null  $short  one sentence for the push, the first line if empty
     */
    public function __construct(
        public string $title,
        public array $lines = [],
        public ?string $url = null,
        public ?string $action = null,
        public ?string $short = null,
        public ?string $greeting = null,
    ) {}

    /**
     * Add a pdf or other file to the mail.
     *
     * @param  Closure(): string  $content
     */
    public function attach(string $name, Closure $content): static
    {
        $this->attachments[$name] = $content;

        return $this;
    }

    /**
     * Text of the push: at most 120 characters.
     */
    public function pushText(): string
    {
        $text = $this->short ?? ($this->lines[0] ?? '');

        return mb_strimwidth(trim(preg_replace('/\s+/', ' ', $text)), 0, 120, '…');
    }
}
