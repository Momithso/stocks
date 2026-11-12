<?php

namespace App\Notifications;

use App\Models\User;
use App\Support\Notifications\NotificationEvents;
use App\Support\Notifications\NotificationMessage;
use App\Support\Notifications\WebPush;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Queue\SerializesModels;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * Base of the notifications of Stocks (documentation/NOTIFICATIONS.md). A notification names its
 * event and builds its content once; the channels follow the settings of the receiver (account or
 * borrower page) and the devices with push, mail and push are made from the same content.
 *
 * Receivers with settings implement notificationPreferences(): array|null (deviations, see
 * NotificationEvents). Accounts use the column "notification_preferences".
 */
abstract class StocksNotification extends Notification implements ShouldQueue
{
    use Queueable;

    // models travel as references through the queue and are loaded fresh for sending
    use SerializesModels;

    /**
     * Key of the event, registered in NotificationEvents.
     */
    abstract public function event(): string;

    /**
     * Content of the notification for a receiver.
     */
    abstract public function message(object $notifiable): NotificationMessage;

    /**
     * Channels of a receiver: mail and push as set for the event; push only with keys and a device.
     *
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        if ($notifiable instanceof AnonymousNotifiable) {
            return array_keys($notifiable->routes) === ['mail'] ? ['mail'] : [];
        }

        $events = app(NotificationEvents::class);
        $event = $events->get($this->event());
        if ($event === null || ($notifiable instanceof User && ($notifiable->is_disabled || ($event['permission'] && ! $notifiable->can($event['permission']))))) {
            return [];
        }

        $preferences = $this->preferencesOf($notifiable);
        $channels = [];
        if ($events->wants($preferences, $this->event(), 'mail') && filled($notifiable->routeNotificationFor('mail', $this))) {
            $channels[] = 'mail';
        }
        if ($events->wants($preferences, $this->event(), 'push') && self::hasPush($notifiable)) {
            $channels[] = WebPushChannel::class;
        }

        return $channels;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $message = $this->message($notifiable);
        $mail = (new MailMessage)->subject($message->title)->greeting($message->greeting ?? 'Hallo!');
        foreach ($message->lines as $line) {
            $mail->line($line);
        }
        if ($message->url) {
            $mail->action($message->action ?? 'Öffnen', $message->url);
        }
        foreach ($message->attachments as $name => $content) {
            $mail->attachData($content(), $name, ['mime' => str_ends_with($name, '.pdf') ? 'application/pdf' : 'application/octet-stream']);
        }

        return $mail;
    }

    public function toWebPush(object $notifiable, mixed $notification = null): WebPushMessage
    {
        $message = $this->message($notifiable);

        return (new WebPushMessage)
            ->title($message->title)
            ->body($message->pushText())
            ->icon('/web-app/icon-192.png')
            ->tag($this->event())
            ->data(['url' => $message->url]);
    }

    /**
     * Called after the notification went out on a channel (listener in AppServiceProvider), e.g.
     * to keep a log. Does nothing by default.
     */
    public function sent(object $notifiable, string $channel, mixed $response): void {}

    /**
     * Check if a receiver has devices with push and push is on.
     */
    public static function hasPush(object $notifiable): bool
    {
        return WebPush::enabled() && method_exists($notifiable, 'pushSubscriptions') && $notifiable->pushSubscriptions()->exists();
    }

    /**
     * Stored deviations of the receiver from the defaults.
     *
     * @return array<string, array<string, bool>>|null
     */
    protected function preferencesOf(object $notifiable): ?array
    {
        return method_exists($notifiable, 'notificationPreferences') ? $notifiable->notificationPreferences() : null;
    }
}
