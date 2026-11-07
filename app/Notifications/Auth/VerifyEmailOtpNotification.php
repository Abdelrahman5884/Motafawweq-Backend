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
        $locale = app()->getLocale() === 'en' ? 'en' : 'ar';

        if ($locale === 'en') {
            return (new MailMessage)
                ->subject('Verify Your Email Address - Motafawweq')
                ->view('emails.auth-notification', [
                    'locale'       => 'en',
                    'title'        => 'Verify Your Email Address',
                    'badge'        => 'ACCOUNT SECURITY',
                    'greeting'     => "Dear {$notifiable->name},",
                    'intro'        => 'We received a request to verify the email address associated with your Motafawweq account. Please use the verification code below to complete your registration and access your account.',
                    'code'         => $this->code,
                    'ttlMinutes'   => $this->ttlMinutes,
                    'securityNote' => 'If you did not request this verification, you can safely ignore this email.',
                ]);
        }

        return (new MailMessage)
            ->subject('تأكيد البريد الإلكتروني - Motafawweq')
            ->view('emails.auth-notification', [
                'locale'       => 'ar',
                'title'        => 'تأكيد البريد الإلكتروني',
                'badge'        => 'أمان الحساب',
                'greeting'     => "عزيزي {$notifiable->name}،",
                'intro'        => 'تلقينا طلباً لتأكيد البريد الإلكتروني المرتبط بحسابك في متفوّق. يرجى استخدام رمز التحقق أدناه لإتمام التفعيل والوصول إلى حسابك.',
                'code'         => $this->code,
                'ttlMinutes'   => $this->ttlMinutes,
                'securityNote' => 'إذا لم تقم بهذا الطلب، يمكنك تجاهل هذه الرسالة بأمان.',
            ]);
    }
}

