<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ControlCenterStaffInvitationNotification extends Notification
{
    use Queueable;

    public function __construct(
        public readonly string $token,
        public readonly string $portalUrl,
        public readonly string $role,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = $this->portalUrl.'/ops/invite?'.http_build_query([
            'token' => $this->token,
            'email' => $notifiable->routes['mail'],
        ]);

        return (new MailMessage)
            ->subject('Invitation to Finova Operations')
            ->greeting('You have been invited to Finova Operations.')
            ->line('An Operations administrator invited you as '.str_replace('_', ' ', $this->role).'.')
            ->line('This invite link expires in 48 hours and can only be used once.')
            ->action('Accept staff invitation', $url)
            ->line('You will choose your password and set up multi-factor authentication before Operations access is enabled.');
    }
}
