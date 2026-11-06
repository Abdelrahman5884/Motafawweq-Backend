<?php

namespace App\Notifications\Auth;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class NewDeviceLoginNotification extends Notification
{
    use Queueable;

    public function __construct(
        public readonly ?string $ip,
        public readonly ?string $userAgent
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('تسجيل دخول من جهاز جديد - متفوق')
            ->greeting("مرحباً {$notifiable->name}،")
            ->line('تم تسجيل الدخول إلى حسابك من جهاز أو موقع جديد:')
            ->line('عنوان IP: ' . ($this->ip ?? 'غير معروف'))
            ->line('الجهاز: ' . ($this->userAgent ?? 'غير معروف'))
            ->line('الوقت: ' . now()->format('Y-m-d H:i'))
            ->line('إذا لم تكن أنت، قم بتغيير كلمة المرور فوراً وتسجيل الخروج من جميع الأجهزة.');
    }
}

