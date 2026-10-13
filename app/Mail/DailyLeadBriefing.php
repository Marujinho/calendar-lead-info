<?php

declare(strict_types=1);

namespace App\Mail;

use App\Data\DigestData;
use App\Models\Rep;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Markdown;

/**
 * The daily lead briefing email (PRD FR-8, NFR-6): one section per meeting
 * with a table of its leads, rendered as HTML and plain text from one
 * Markdown view.
 *
 * Invariants:
 *  - The subject is a fixed template plus the local date; no provider data
 *    reaches the subject or any other header (A05). The recipient is set by
 *    the caller (MailableDigestMailer), never from provider data.
 *  - Provider fields are echoed with `{{ }}` only, after MarkdownCell has
 *    flattened them to one line. The HTML part is rendered with Laravel's
 *    secured Markdown encoding, so `[`, `]`, `<` and `>` in provider data
 *    cannot create links, images or tags.
 *  - A LinkedIn URL becomes a link only when LinkedInUrl::safe() accepts it.
 *  - A non-null `$dataRefreshedAt` means stale data: the "last refreshed at"
 *    notice is shown in the rep's zone. Null means fresh: no notice.
 *  - Not queued: the send job owns queuing and retries.
 */
final class DailyLeadBriefing extends Mailable
{
    public function __construct(
        public readonly DigestData $digest,
        public readonly Rep $rep,
        public readonly ?CarbonImmutable $dataRefreshedAt = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Your lead briefing for '.$this->digest->localDate->format('l, j F Y'),
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.daily-lead-briefing',
            with: [
                'isHtml' => false,
                'cell' => static fn (?string $value): string => MarkdownCell::of($value, false),
                'refreshedAtLocal' => $this->refreshedAtLocal(),
            ],
        );
    }

    /** The stale notice time in the rep's zone, or null when the data is fresh. */
    public function refreshedAtLocal(): ?string
    {
        if ($this->dataRefreshedAt === null) {
            return null;
        }

        return $this->dataRefreshedAt->setTimezone($this->digest->timezone)->format('j M Y, H:i')
            .' ('.$this->digest->timezone.')';
    }

    /**
     * Renders the HTML part with HTML-specific cell formatting and Laravel's
     * secured Markdown encoding switched on for this render only. The text
     * part is plain text and is rendered without it.
     *
     * @param  array<string, mixed>  $viewData
     * @return Closure(array<string, mixed>): mixed
     */
    protected function buildMarkdownHtml($viewData): Closure
    {
        $render = parent::buildMarkdownHtml(array_merge($viewData, [
            'isHtml' => true,
            'cell' => static fn (?string $value): string => MarkdownCell::of($value, true),
        ]));

        return static function (array $data) use ($render): mixed {
            Markdown::withSecuredEncoding();

            try {
                return $render($data);
            } finally {
                Markdown::withoutSecuredEncoding();
            }
        };
    }
}
