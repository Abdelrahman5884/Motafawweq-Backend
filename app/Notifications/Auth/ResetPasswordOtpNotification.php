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
        $locale = app()->getLocale() === 'en' ? 'en' : 'ar';

        if ($locale === 'en') {
            return (new MailMessage)
                ->subject("Reset Your Password ({$this->code}) - Motafawweq")
                ->view('emails.auth-notification', [
                    'locale'       => 'en',
                    'title'        => 'Reset Your Password',
                    'badge'        => 'ACCOUNT SECURITY',
                    'greeting'     => "Dear {$notifiable->name},",
                    'intro'        => 'We received a request to reset the password for your Motafawweq account. Please use the verification code below to choose a new password and regain access to your account.',
                    'code'         => $this->code,
                    'ttlMinutes'   => $this->ttlMinutes,
                    'securityNote' => 'If you did not request a password reset, please ignore this email. Your password will remain unchanged.',
                ]);
        }

        return (new MailMessage)
            ->subject("إعادة تعيين كلمة المرور ({$this->code}) - Motafawweq")
            ->view('emails.auth-notification', [
                'locale'       => 'ar',
                'title'        => 'إعادة تعيين كلمة المرور',
                'badge'        => 'أمان الحساب',
                'greeting'     => "عزيزي {$notifiable->name}،",
                'intro'        => 'تلقينا طلباً لإعادة تعيين كلمة المرور الخاصة بحسابك في متفوّق. يرجى استخدام رمز التحقق أدناه لاختيار كلمة مرور جديدة واستعادة الوصول إلى حسابك.',
                'code'         => $this->code,
                'ttlMinutes'   => $this->ttlMinutes,
                'securityNote' => 'إذا لم تطلب إعادة تعيين كلمة المرور، يرجى تجاهل هذه الرسالة وستبقى كلمة المرور الحالية دون تغيير.',
            ]);
    }
}

