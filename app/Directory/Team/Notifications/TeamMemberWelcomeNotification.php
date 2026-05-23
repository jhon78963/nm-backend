<?php

namespace App\Directory\Team\Notifications;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Notifications\Messages\MailMessage;

class TeamMemberWelcomeNotification extends ResetPassword
{
    public function toMail($notifiable): MailMessage
    {
        $expireMinutes = (int) config('auth.passwords.users.expire', 60);

        return (new MailMessage)
            ->subject('Bienvenido — establece tu contraseña')
            ->greeting('Hola '.$notifiable->name)
            ->line('Se creó tu cuenta de acceso al sistema.')
            ->line('Tu usuario es: **'.$notifiable->username.'**')
            ->action('Establecer contraseña', $this->resetUrl($notifiable))
            ->line('Este enlace expira en '.$expireMinutes.' minutos.')
            ->line('Si no esperabas este correo, puedes ignorarlo.');
    }

    protected function resetUrl($notifiable): string
    {
        $frontendUrl = rtrim((string) config('services.frontend.url'), '/');

        if ($frontendUrl === '') {
            $frontendUrl = rtrim((string) config('app.url'), '/');
        }

        return $frontendUrl.'/#/auth/reset-password?token='.$this->token
            .'&email='.urlencode($notifiable->getEmailForPasswordReset());
    }
}
