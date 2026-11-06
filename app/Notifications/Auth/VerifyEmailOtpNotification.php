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
        return (new MailMessage)
            ->subject('رمز تأكيد البريد الإلكتروني - متفوق')
            ->greeting("مرحباً {$notifiable->name}،")
            ->line('رمز تأكيد بريدك الإلكتروني هو:')
            ->line("**{$this->code}**")
            ->line("صلاحية الرمز {$this->ttlMinutes} دقائق فقط.")
            ->line('إذا لم تقم بإنشاء حساب على منصة متفوق، يرجى تجاهل هذه الرسالة.');
    }
}

