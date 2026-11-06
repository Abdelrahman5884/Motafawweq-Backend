<?php

namespace App\Notifications\Auth;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class VerifyEmailOtpNotification extends Notification
{
    use Queueable;

    public function __construct(
        public readonly string $code,
        public readonly int $ttlMinutes
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        if (app()->getLocale() === 'en') {
            return (new MailMessage)
                ->subject('Email Verification Code - Motafawweq')
                ->greeting("Hello {$notifiable->name},")
                ->line('Your email verification code is:')
                ->line("**{$this->code}**")
                ->line("This code is valid for {$this->ttlMinutes} minutes.")
                ->line('If you did not create an account on Motafawweq, please ignore this email.');
        }

        return (new MailMessage)
            ->subject('رمز تأكيد البريد الإلكتروني - متفوق')
            ->greeting("مرحباً {$notifiable->name}،")
            ->line('رمز تأكيد بريدك الإلكتروني هو:')
            ->line("**{$this->code}**")
            ->line("صلاحية الرمز {$this->ttlMinutes} دقائق فقط.")
            ->line('إذا لم تقم بإنشاء حساب على منصة متفوق، يرجى تجاهل هذه الرسالة.');
    }
}

