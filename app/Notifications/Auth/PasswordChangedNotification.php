<?php

namespace App\Notifications\Auth;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PasswordChangedNotification extends Notification
{
    use Queueable;

    public function __construct(
        public readonly ?string $ip = null
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('تم تغيير كلمة المرور - متفوق')
            ->greeting("مرحباً {$notifiable->name}،")
            ->line('تم تغيير كلمة المرور الخاصة بحسابك بنجاح، وتم تسجيل الخروج من جميع الأجهزة الأخرى.')
            ->line('عنوان IP: ' . ($this->ip ?? 'غير معروف'))
            ->line('الوقت: ' . now()->format('Y-m-d H:i'))
            ->line('إذا لم تقم بهذا التغيير، تواصل مع الدعم فوراً.');
    }
}

