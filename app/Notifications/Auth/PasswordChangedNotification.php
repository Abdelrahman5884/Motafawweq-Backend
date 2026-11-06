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
        if (app()->getLocale() === 'en') {
            return (new MailMessage)
                ->subject('Password Changed - Motafawweq')
                ->greeting("Hello {$notifiable->name},")
                ->line('Your account password was changed successfully, and all other active devices have been signed out.')
                ->line('IP Address: ' . ($this->ip ?? 'Unknown'))
                ->line('Time: ' . now()->format('Y-m-d H:i'))
                ->line('If you did not make this change, please contact support immediately.');
        }

        return (new MailMessage)
            ->subject('تم تغيير كلمة المرور - متفوق')
            ->greeting("مرحباً {$notifiable->name}،")
            ->line('تم تغيير كلمة المرور الخاصة بحسابك بنجاح، وتم تسجيل الخروج من جميع الأجهزة الأخرى.')
            ->line('عنوان IP: ' . ($this->ip ?? 'غير معروف'))
            ->line('الوقت: ' . now()->format('Y-m-d H:i'))
            ->line('إذا لم تقم بهذا التغيير، تواصل مع الدعم فوراً.');
    }
}

