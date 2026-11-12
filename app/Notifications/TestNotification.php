<?php

namespace App\Notifications;

use App\Support\Notifications\NotificationMessage;
use App\Support\Settings;
use Illuminate\Notifications\AnonymousNotifiable;
use NotificationChannels\WebPush\WebPushChannel;

/**
 * Test notification on one channel, regardless of the settings: button in the profile and
 * "php artisan stocks:notify-test". Shows whether mail or push reach a device.
 */
class TestNotification extends StocksNotification
{
    /**
     * @param  string  $channel  "mail" or "push"
     */
    public function __construct(public readonly string $channel) {}

    public function event(): string
    {
        return 'core.test';
    }

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        if ($this->channel === 'push') {
            return $notifiable instanceof AnonymousNotifiable || ! self::hasPush($notifiable) ? [] : [WebPushChannel::class];
        }

        return ['mail'];
    }

    public function message(object $notifiable): NotificationMessage
    {
        $organization = app(Settings::class)->get('organization.name');

        return new NotificationMessage(
            title: "Testbenachrichtigung von {$organization}",
            lines: ['Wenn du das liest, kommen Benachrichtigungen von Stocks auf diesem Weg bei dir an.'],
            url: route('profile.edit'),
            action: 'Profil öffnen',
            short: 'Push kommt an. Ein Tipp hier öffnet dein Profil.',
        );
    }
}
