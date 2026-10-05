<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ResetPasswordLinkNotification extends Notification
{
    use Queueable;

    public function __construct(private readonly string $token) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $query = http_build_query([
            'token' => $this->token,
            'email' => $notifiable->getEmailForPasswordReset(),
        ]);
        $url = rtrim(config('services.frontend_url'), '/') . '/reset-password?' . $query;

        return (new MailMessage)
            ->subject('Reset your Finance ERP password')
            ->line('We received a request to reset your account password.')
            ->action('Reset password', $url)
            ->line('This link expires in 60 minutes. If you did not request a reset, you can ignore this email.');
    }
}
