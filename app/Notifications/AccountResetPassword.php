<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Notifications\Messages\MailMessage;

class AccountResetPassword extends ResetPassword
{
    public function toMail($notifiable): MailMessage
    {
        // Fragments are not transmitted to the server, proxies or access logs.
        $url = rtrim(config('app.url'), '/').'/reinitialiser-mot-de-passe#'.http_build_query(['token' => $this->token, 'email' => $notifiable->getEmailForPasswordReset()]);

        return (new MailMessage)->subject('Réinitialiser votre mot de passe Convert')->greeting('Bonjour,')
            ->line('Vous avez demandé un nouveau mot de passe. Ce lien expire dans 60 minutes.')
            ->action('Choisir un nouveau mot de passe', $url)->line('Si vous n’êtes pas à l’origine de cette demande, ignorez ce message.');
    }
}
