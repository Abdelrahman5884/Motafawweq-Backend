@php
    $isRtl = ($locale ?? app()->getLocale()) === 'ar';
    $dir = $isRtl ? 'rtl' : 'ltr';
    $align = $isRtl ? 'right' : 'left';
    $fontFamily = "'Segoe UI', Tahoma, Arial, sans-serif";
    $logoUrl = config('app.logo_url', 'https://raw.githubusercontent.com/Abdelrahman5884/Motafawweq-Frontend/main/public/logo-light.png');
@endphp
<!DOCTYPE html>
<html lang="{{ $isRtl ? 'ar' : 'en' }}" dir="{{ $dir }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $title ?? 'Motafawweq' }}</title>
</head>
<body style="margin: 0; padding: 0; background-color: #f4f7fb; font-family: {{ $fontFamily }}; direction: {{ $dir }};">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background-color: #f4f7fb; padding: 40px 16px;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="max-width: 580px; background-color: #ffffff; border: 1px solid #dbe4f0;">

                    <!-- Motafawweq Deep Navy Header (#0B2C58) -->
                    <tr>
                        <td align="center" style="background-color: #0b2c58; padding: 34px 24px 30px 24px; text-align: center; border-bottom: 3px solid #1588c7;">
                            <!-- Official Motafawweq Logo -->
                            <div style="display: inline-block; background-color: #ffffff; padding: 8px 12px; border-radius: 12px; margin-bottom: 14px;">
                                <img src="{{ $logoUrl }}" alt="Motafawweq" width="56" height="56" style="display: block; width: 56px; height: 56px; object-fit: contain; border: 0; margin: 0 auto;">
                            </div>

                            <!-- English Brand Name -->
                            <div dir="ltr" style="font-family: Georgia, 'Times New Roman', serif; font-size: 21px; font-weight: 400; letter-spacing: 6px; color: #ffffff; text-transform: uppercase; margin: 0;">
                                M O T A F A W W E Q
                            </div>

                            <!-- Sky Blue Brand Divider Line (#38BDF8) -->
                            <div style="width: 38px; height: 2px; background-color: #38bdf8; margin: 13px auto 11px auto; line-height: 2px; font-size: 2px;">&nbsp;</div>

                            <!-- Subtle Tagline -->
                            <div style="font-family: {{ $fontFamily }}; font-size: 11.5px; color: #93c5fd;">
                                {{ $isRtl ? 'رحلتك نحو التفوق' : 'EDUCATION & EXCELLENCE' }}
                            </div>
                        </td>
                    </tr>

                    <!-- Main Body -->
                    <tr>
                        <td dir="{{ $dir }}" style="padding: 44px 46px 38px 46px; background-color: #ffffff; direction: {{ $dir }};">

                            @if(!empty($badge))
                                <div style="text-align: center; font-family: {{ $fontFamily }}; font-size: 12px; font-weight: 700; color: #1588c7; margin-bottom: 10px;">
                                    {{ $badge }}
                                </div>
                            @endif

                            <h1 style="margin: 0; text-align: center; font-family: {{ $isRtl ? $fontFamily : "Georgia, 'Times New Roman', serif" }}; font-size: 25px; font-weight: {{ $isRtl ? '700' : '400' }}; color: #0b2c58; line-height: 1.35;">
                                {{ $title }}
                            </h1>

                            <div style="width: 44px; height: 1px; background-color: #cbd5e1; margin: 20px auto 32px auto; line-height: 1px; font-size: 1px;">&nbsp;</div>

                            <p style="margin: 0 0 16px 0; font-family: {{ $fontFamily }}; font-size: 15px; font-weight: 700; color: #0b2c58; text-align: {{ $align }};">
                                {{ $greeting }}
                            </p>

                            <p style="margin: 0 0 30px 0; font-family: {{ $fontFamily }}; font-size: 15px; line-height: 1.85; color: #334155; text-align: {{ $align }};">
                                {{ $intro }}
                            </p>

                            @if(!empty($code))
                                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="margin: 0 0 26px 0;">
                                    <tr>
                                        <td align="center" style="text-align: center;">
                                            <table role="presentation" cellspacing="0" cellpadding="0" border="0" align="center" style="margin: 0 auto;">
                                                <tr>
                                                    <td align="center" style="background-color: #0b2c58; border: 1px solid #1588c7; border-radius: 4px; padding: 15px 40px;">
                                                        <span dir="ltr" style="font-family: 'Courier New', Consolas, monospace; font-size: 26px; font-weight: 700; letter-spacing: 4px; color: #ffffff; user-select: all; -webkit-user-select: all; -moz-user-select: all; cursor: text;">{{ trim($code) }}</span>
                                                    </td>
                                                </tr>
                                            </table>

                                            <div style="margin-top: 10px; font-family: {{ $fontFamily }}; font-size: 12px; color: #1588c7; font-weight: 600;">
                                                {{ $isRtl ? 'اضغط مرتين على الرمز لتحديده ونسخه' : 'Double-click the code to select and copy' }}
                                            </div>

                                            @if(!empty($ttlMinutes))
                                                <div style="margin-top: 4px; font-family: {{ $fontFamily }}; font-size: 12px; color: #64748b;">
                                                    {{ $isRtl ? "صلاحية الرمز {$ttlMinutes} دقائق." : "This code expires in {$ttlMinutes} minutes." }}
                                                </div>
                                            @endif
                                        </td>
                                    </tr>
                                </table>
                            @endif

                            @if(!empty($details) && is_array($details))
                                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" dir="{{ $dir }}" style="margin: 0 0 26px 0; background-color: #f8fafc; border: 1px solid #dbe4f0;">
                                    @foreach($details as $label => $value)
                                        <tr>
                                            <td style="padding: 12px 18px; border-bottom: 1px solid #dbe4f0; font-family: {{ $fontFamily }}; font-size: 13px; font-weight: 700; color: #475569; width: 35%; text-align: {{ $align }};">
                                                {{ $label }}
                                            </td>
                                            <td dir="ltr" style="padding: 12px 18px; border-bottom: 1px solid #dbe4f0; font-family: {{ $fontFamily }}; font-size: 13px; color: #0b2c58; text-align: {{ $align }};">
                                                {{ $value }}
                                            </td>
                                        </tr>
                                    @endforeach
                                </table>
                            @endif

                            @if(!empty($securityNote))
                                <p style="margin: 0; font-family: {{ $fontFamily }}; font-size: 13.5px; line-height: 1.75; color: #64748b; text-align: {{ $align }};">
                                    {{ $securityNote }}
                                </p>
                            @endif

                        </td>
                    </tr>

                    <!-- Minimal Footer -->
                    <tr>
                        <td align="center" style="background-color: #f8fafc; border-top: 1px solid #dbe4f0; padding: 20px 32px; text-align: center;">
                            <p dir="ltr" style="margin: 0; font-family: {{ $fontFamily }}; font-size: 11.5px; color: #64748b;">
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
