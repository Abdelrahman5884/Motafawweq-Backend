<?php

namespace App\Notifications\Auth;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class VerifyEmailOtpNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;
    public int $timeout = 30;

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
        $frontendUrl = rtrim((string) env('FRONTEND_URL', 'http://localhost:5173'), '/');
        $copyUrl = "{$frontendUrl}/?otp=" . urlencode($this->code);

        if ($locale === 'en') {
            return (new MailMessage)
                ->subject('Verify Your Email Address - Motafawweq')
                ->view('emails.auth-notification', [
                    'locale'       => 'en',
                    'title'        => 'Verify Your Email Address',
                    'badge'        => 'ACCOUNT SECURITY',
                    'greeting'     => "Hello {$notifiable->name},",
                    'intro'        => 'We received a request to verify the email address associated with your Motafawweq account. Please use the verification code below to complete your registration and access your account.',
                    'code'         => $this->code,
                    'copyUrl'      => $copyUrl,
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
                'greeting'     => "مرحباً {$notifiable->name}،",
                'intro'        => 'تلقينا طلباً لتأكيد البريد الإلكتروني المرتبط بحسابك في متفوّق. يرجى استخدام رمز التحقق أدناه لإتمام التفعيل والوصول إلى حسابك.',
                'code'         => $this->code,
                'copyUrl'      => $copyUrl,
                'ttlMinutes'   => $this->ttlMinutes,
                'securityNote' => 'إذا لم تقم بهذا الطلب، يمكنك تجاهل هذه الرسالة بأمان.',
            ]);
    }
}

