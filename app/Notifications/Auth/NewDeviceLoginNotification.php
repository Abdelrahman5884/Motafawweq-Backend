<?php

namespace App\Notifications\Auth;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class NewDeviceLoginNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;
    public int $timeout = 30;

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
        $locale = app()->getLocale() === 'en' ? 'en' : 'ar';

        if ($locale === 'en') {
            return (new MailMessage)
                ->subject('New Sign-In Detected - Motafawweq')
                ->view('emails.auth-notification', [
                    'locale'   => 'en',
                    'title'    => 'New Sign-In Detected',
                    'badge'    => 'ACCOUNT SECURITY',
                    'greeting' => "Dear {$notifiable->name},",
                    'intro'    => 'We detected a new sign-in to your Motafawweq account from an unrecognized device:',
                    'details'  => [
                        'IP Address' => $this->ip ?? 'Unknown',
                        'Device'     => $this->userAgent ?? 'Unknown',
                        'Time (UTC)' => now()->format('Y-m-d H:i'),
                    ],
                    'securityNote' => 'If this was not you, please change your password immediately and review your active sessions.',
                ]);
        }

        return (new MailMessage)
            ->subject('تسجيل دخول جديد - Motafawweq')
            ->view('emails.auth-notification', [
                'locale'   => 'ar',
                'title'    => 'تسجيل دخول جديد',
                'badge'    => 'أمان الحساب',
                'greeting' => "عزيزي {$notifiable->name}،",
                'intro'    => 'تم تسجيل الدخول إلى حسابك في متفوّق من جهاز جديد بالتفاصيل التالية:',
                'details'  => [
                    'عنوان الـ IP' => $this->ip ?? 'غير معروف',
                    'الجهاز'       => $this->userAgent ?? 'غير معروف',
                    'التوقيت'      => now()->format('Y-m-d H:i'),
                ],
                'securityNote' => 'إذا لم تكن أنت من قام بتسجيل الدخول، يرجى تغيير كلمة المرور فوراً وإنهاء الجلسات النشطة من الإعدادات.',
            ]);
    }
}

