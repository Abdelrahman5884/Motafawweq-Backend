<?php

namespace App\Notifications\Auth;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ResetPasswordOtpNotification extends Notification
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
            ->subject('رمز إعادة تعيين كلمة المرور - متفوق')
            ->greeting("مرحباً {$notifiable->name}،")
            ->line('تلقينا طلباً لإعادة تعيين كلمة المرور الخاصة بحسابك. رمز التحقق هو:')
            ->line("**{$this->code}**")
            ->line("صلاحية الرمز {$this->ttlMinutes} دقائق فقط.")
            ->line('إذا لم تطلب ذلك، تجاهل الرسالة وننصحك بتغيير كلمة المرور فوراً.');
    }
}

