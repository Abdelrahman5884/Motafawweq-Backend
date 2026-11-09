<?php

namespace App\Notifications\Auth;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PasswordChangedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;
    public int $timeout = 30;

    public function __construct(
        public readonly ?string $ip = null
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $locale = app()->getLocale() === 'en' ? 'en' : 'ar';

        if ($locale === 'en') {
            return (new MailMessage)
                ->subject('Password Changed - Motafawweq')
                ->view('emails.auth-notification', [
                    'locale'   => 'en',
                    'title'    => 'Password Changed',
                    'badge'    => 'ACCOUNT SECURITY',
                    'greeting' => "Hello {$notifiable->name},",
                    'intro'    => 'The password for your Motafawweq account was recently changed, and all other active sessions have been signed out.',
                    'details'  => [
                        'IP Address' => $this->ip ?? 'Unknown',
                        'Time (UTC)' => now()->format('Y-m-d H:i'),
                    ],
                    'securityNote' => 'If you did not make this change, please reset your password immediately.',
                ]);
        }

        return (new MailMessage)
            ->subject('تم تغيير كلمة المرور - Motafawweq')
            ->view('emails.auth-notification', [
                'locale'   => 'ar',
                'title'    => 'تم تغيير كلمة المرور',
                'badge'    => 'أمان الحساب',
                'greeting' => "مرحباً {$notifiable->name}،",
                'intro'    => 'تم تغيير كلمة المرور الخاصة بحسابك في متفوّق بنجاح، وتم تسجيل الخروج من جميع الأجهزة الأخرى لحماية حسابك.',
                'details'  => [
                    'عنوان الـ IP' => $this->ip ?? 'غير معروف',
                    'التوقيت'      => now()->format('Y-m-d H:i'),
                ],
                'securityNote' => 'إذا لم تقم بهذا الإجراء، يرجى إعادة تعيين كلمة المرور فوراً.',
            ]);
    }
}

