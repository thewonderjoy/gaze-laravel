<?php

declare(strict_types=1);

namespace TheWonderJoy\Gaze\Laravel\Watchers;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Mail\Events\MessageSending;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Part\DataPart;
use TheWonderJoy\Gaze\Laravel\GazeManager;
use TheWonderJoy\Gaze\Laravel\Support\Values;

/**
 * Every mail Laravel sends (MessageSending), with the full envelope taken from the Symfony
 * Email: from, to, cc, bcc, reply-to, subject, HTML and text bodies, attachments, the mailable
 * or notification class. laravel-ray on Laravel 11+ sent only the body (from/to/subject empty).
 *
 * The listener returns nothing: MessageSending is dispatched with until(), where a non-null
 * answer would stop other listeners and false would cancel the mail.
 */
final class MailWatcher extends Watcher
{
    public const MAX_HTML = 524288;

    protected function register(Dispatcher $events): void
    {
        $events->listen(MessageSending::class, $this->handle(...));
    }

    public function handle(MessageSending $event): void
    {
        if (!$this->on('mails') || GazeManager::sending()) {
            return;
        }
        try {
            $this->gaze->currentTrace()->count('mails');
            $data = is_array($event->data) ? $event->data : [];
            $body = self::body($event->message, $data['__laravel_mailable'] ?? null, $data['__laravel_notification'] ?? null);
            $this->send('mail', $body);
        } catch (\Throwable) {
        }
    }

    /** @return array<string, mixed> IPC contract "mail" body plus text, reply_to, attachments, headers */
    public static function body(object $message, mixed $mailable = null, mixed $notification = null): array
    {
        $body = [
            'html' => '',
            'subject' => null,
            'from' => [], 'to' => [], 'cc' => [], 'bcc' => [], 'reply_to' => [],
            'mailable' => is_string($mailable) ? $mailable : null,
        ];
        if (is_string($notification)) {
            $body['notification'] = $notification;
        }
        if (!$message instanceof Email) {
            return $body;
        }
        $body['subject'] = $message->getSubject();
        $body['from'] = Values::addresses($message->getFrom());
        $body['to'] = Values::addresses($message->getTo());
        $body['cc'] = Values::addresses($message->getCc());
        $body['bcc'] = Values::addresses($message->getBcc());
        $body['reply_to'] = Values::addresses($message->getReplyTo());
        $html = self::text($message->getHtmlBody());
        $text = self::text($message->getTextBody());
        if ($html !== null) {
            [$body['html'], $cut] = Values::cut($html, self::MAX_HTML);
            if ($cut > 0) {
                $body['html_cut'] = $cut;
            }
        } elseif ($text !== null) {
            $body['html'] = '<pre style="white-space:pre-wrap">' . htmlspecialchars(Values::cut($text, self::MAX_HTML)[0], ENT_QUOTES) . '</pre>';
        }
        if ($text !== null) {
            $body['text'] = Values::cut($text, self::MAX_HTML)[0];
        }
        $attachments = [];
        foreach ($message->getAttachments() as $part) {
            if ($part instanceof DataPart) {
                $size = null;
                try {
                    $size = strlen($part->getBody());
                } catch (\Throwable) {
                }
                $attachments[] = array_filter([
                    'name' => $part->getFilename() ?? $part->getName(),
                    'content_type' => $part->getMediaType() . '/' . $part->getMediaSubtype(),
                    'size' => $size,
                    'inline' => $part->getDisposition() === 'inline' ? true : null,
                ], static fn ($v) => $v !== null);
            }
        }
        if ($attachments) {
            $body['attachments'] = $attachments;
        }
        if ($priority = $message->getPriority()) {
            if ($priority !== Email::PRIORITY_NORMAL) {
                $body['priority'] = $priority;
            }
        }

        return $body;
    }

    /** @param resource|string|null $body */
    private static function text(mixed $body): ?string
    {
        if (is_resource($body)) {
            $contents = stream_get_contents($body);
            @rewind($body);

            return $contents === false ? null : $contents;
        }

        return is_string($body) ? $body : null;
    }
}
