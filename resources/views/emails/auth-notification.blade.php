@php
    $isRtl = ($locale ?? app()->getLocale()) === 'ar';
    $dir = $isRtl ? 'rtl' : 'ltr';
    $align = $isRtl ? 'right' : 'left';
    $fontFamily = "'Cairo', 'Segoe UI', Tahoma, Arial, sans-serif";
    $logoUrl = config('app.logo_url', 'https://raw.githubusercontent.com/Abdelrahman5884/Motafawweq-Frontend/main/public/logo-light.png');
@endphp
<!DOCTYPE html>
<html lang="{{ $isRtl ? 'ar' : 'en' }}" dir="{{ $dir }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $title ?? 'Motafawweq' }}</title>
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700&display=swap" rel="stylesheet">
</head>
<body style="margin: 0; padding: 0; background-color: #efece6; font-family: {{ $fontFamily }}; direction: {{ $dir }}; -webkit-font-smoothing: antialiased;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background-color: #efece6; padding: 40px 16px;">
        <tr>
            <td align="center">
                <!-- Editorial Card Container -->
                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="max-width: 580px; background-color: #faf8f5; border: 1px solid #e4dfd7;">

                    <!-- Deep Navy Minimal Header -->
                    <tr>
                        <td align="center" style="background-color: #111e34; padding: 36px 24px 32px 24px; text-align: center;">
                            <!-- Small Logo Icon -->
                            <div style="display: inline-block; background-color: #ffffff; padding: 5px; border-radius: 10px; margin-bottom: 14px;">
                                <img src="{{ $logoUrl }}" alt="Motafawweq" width="42" height="42" style="display: block; width: 42px; height: 42px; object-fit: contain; border: 0;">
                            </div>

                            <!-- Spaced English Brand Name -->
                            <div dir="ltr" style="font-family: Georgia, 'Times New Roman', serif; font-size: 21px; font-weight: 400; letter-spacing: 7px; color: #ffffff; text-transform: uppercase; margin: 0;">
                                M O T A F A W W E Q
                            </div>

                            <!-- Gold Divider Line -->
                            <div style="width: 38px; height: 1px; background-color: #b89b5e; margin: 13px auto 11px auto; line-height: 1px; font-size: 1px;">&nbsp;</div>

                            <!-- Subtle Tagline -->
                            <div style="font-family: {{ $fontFamily }}; font-size: 11px; letter-spacing: {{ $isRtl ? '0.5px' : '2.5px' }}; color: #8fa0b8; text-transform: uppercase;">
                                {{ $isRtl ? 'رحلتك نحو التفوق' : 'EDUCATION & EXCELLENCE' }}
                            </div>
                        </td>
                    </tr>

                    <!-- Main Body -->
                    <tr>
                        <td dir="{{ $dir }}" style="padding: 46px 48px 40px 48px; background-color: #faf8f5; direction: {{ $dir }};">

                            <!-- Centered Category Label -->
                            @if(!empty($badge))
                                <div style="text-align: center; font-family: {{ $fontFamily }}; font-size: 12px; font-weight: 700; letter-spacing: {{ $isRtl ? '0.5px' : '2.5px' }}; color: #a3864c; text-transform: uppercase; margin-bottom: 10px;">
                                    {{ $badge }}
                                </div>
                            @endif

                            <!-- Centered Editorial Title -->
                            <h1 style="margin: 0; text-align: center; font-family: {{ $isRtl ? $fontFamily : "Georgia, 'Times New Roman', serif" }}; font-size: 26px; font-weight: {{ $isRtl ? '700' : '400' }}; color: #111e34; line-height: 1.35;">
                                {{ $title }}
                            </h1>

                            <!-- Centered Subtle Rule -->
                            <div style="width: 44px; height: 1px; background-color: #dcd6cc; margin: 20px auto 34px auto; line-height: 1px; font-size: 1px;">&nbsp;</div>

                            <!-- Greeting -->
                            <p style="margin: 0 0 16px 0; font-family: {{ $fontFamily }}; font-size: 15px; font-weight: 600; color: #1e293b; text-align: {{ $align }};">
                                {{ $greeting }}
                            </p>

                            <!-- Main Paragraph -->
                            <p style="margin: 0 0 32px 0; font-family: {{ $fontFamily }}; font-size: 15px; line-height: 1.85; color: #374151; text-align: {{ $align }};">
                                {{ $intro }}
                            </p>

                            <!-- Solid Navy OTP / Action Block -->
                            @if(!empty($code))
                                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="margin: 0 0 28px 0;">
                                    <tr>
                                        <td align="center" style="text-align: center;">
                                            <div dir="ltr" style="display: inline-block; background-color: #111e34; color: #ffffff; font-family: 'Courier New', Consolas, monospace; font-size: 24px; font-weight: 700; letter-spacing: 8px; padding: 17px 42px 17px 50px;">
                                                {{ $code }}
                                            </div>

                                            @if(!empty($ttlMinutes))
                                                <div style="margin-top: 12px; font-family: {{ $fontFamily }}; font-size: 12.5px; color: #6b7280;">
                                                    {{ $isRtl ? "صلاحية الرمز {$ttlMinutes} دقائق." : "This code expires in {$ttlMinutes} minutes." }}
                                                </div>
                                            @endif
                                        </td>
                                    </tr>
                                </table>
                            @endif

                            <!-- Details Table (for Security / Device Notifications) -->
                            @if(!empty($details) && is_array($details))
                                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" dir="{{ $dir }}" style="margin: 0 0 28px 0; background-color: #f3f0ea; border: 1px solid #e4dfd7;">
                                    @foreach($details as $label => $value)
                                        <tr>
                                            <td style="padding: 12px 18px; border-bottom: 1px solid #e4dfd7; font-family: {{ $fontFamily }}; font-size: 13px; font-weight: 700; color: #4b5563; width: 35%; text-align: {{ $align }};">
                                                {{ $label }}
                                            </td>
                                            <td dir="ltr" style="padding: 12px 18px; border-bottom: 1px solid #e4dfd7; font-family: {{ $fontFamily }}; font-size: 13px; color: #111e34; text-align: {{ $align }};">
                                                {{ $value }}
                                            </td>
                                        </tr>
                                    @endforeach
                                </table>
                            @endif

                            <!-- Quiet Security Note -->
                            @if(!empty($securityNote))
                                <p style="margin: 0; font-family: {{ $fontFamily }}; font-size: 13.5px; line-height: 1.75; color: #6b7280; text-align: {{ $align }};">
                                    {{ $securityNote }}
                                </p>
                            @endif

                        </td>
                    </tr>

                    <!-- Minimal Footer -->
                    <tr>
                        <td align="center" style="background-color: #f3f0ea; border-top: 1px solid #e4dfd7; padding: 20px 32px; text-align: center;">
                            <p dir="ltr" style="margin: 0; font-family: {{ $fontFamily }}; font-size: 11.5px; color: #78716c; letter-spacing: 0.5px;">
                                © {{ date('Y') }} Motafawweq. All rights reserved.
                            </p>
                        </td>
                    </tr>

                </table>
            </td>
        </tr>
    </table>
</body>
</html>
