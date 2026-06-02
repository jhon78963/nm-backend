<?php

namespace App\Auth\Notifications;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Notifications\Messages\MailMessage;

class ForgotPasswordNotification extends ResetPassword
{
    public function toMail($notifiable): MailMessage
    {
        $expireMinutes = (int) config('auth.passwords.users.expire', 60);

        return (new MailMessage)
            ->subject('Restablecer contraseña')
            ->greeting('Hola '.$notifiable->name)
            ->line('Recibimos una solicitud para restablecer la contraseña de tu cuenta.')
            ->action('Restablecer contraseña', $this->resetUrl($notifiable))
            ->line('Este enlace expira en '.$expireMinutes.' minutos.')
            ->line('Si no solicitaste este cambio, puedes ignorar este correo.');
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
