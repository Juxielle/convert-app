<?php

namespace App\Services;

class AccountMail
{
    public static function available(): bool
    {
        $transport = config('mail.mailers.'.config('mail.default').'.transport');

        // Reject composite mailers too: a failover to log would disclose reset tokens.
        return in_array($transport, ['smtp', 'sendmail', 'ses', 'ses-v2', 'postmark', 'resend', 'mailgun'], true);
    }
}
