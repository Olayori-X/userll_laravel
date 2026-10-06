<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The one notification the whole app sends. What to say lives in App\Services\Notifier;
 * this class only knows how to deliver it: always into the user's inbox, and by email when asked.
 */
class UserNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  string  $kind   machine name the frontend can switch on, e.g. "order.paid"
     * @param  string|null  $link   a frontend path, e.g. "/orders/ORD-123"
     * @param  array<string, mixed>  $meta  ids the frontend may need (order id, payout id...)
     */
        public function __construct(
        public string $kind,
        public string $title,
        public string $body,
        public ?string $link = null,
        public array $meta = [],
        public bool $email = false,
    ) {        
    }

    public function via(object $notifiable): array
    {
        return $this->email ? ['database', 'mail'] : ['database'];
    }

    /** What is stored in the inbox and returned by the API. */
    public function toDatabase(object $notifiable): array
    {
        return [
            'kind' => $this->kind,
            'title' => $this->title,
            'body' => $this->body,
            'link' => $this->link,
            'meta' => $this->meta,
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $name = trim((string) ($notifiable->name ?? ''));

        $mail = (new MailMessage)
            ->subject($this->title)
            ->greeting($name !== '' ? "Hello {$name}," : 'Hello,')
            ->line($this->body);

        if ($this->link) {
            $mail->action('View details', rtrim((string) config('marketplace.frontend_url'), '/').$this->link);
        }

        return $mail;
    }
}