{{--
| Motafawweq — Email Layout v3 (dark canvas)
| Required : $title, $greeting, $intro
| Optional : $locale, $badge, $code, $copyUrl, $ttlMinutes, $details (array),
|            $securityNote, $actionUrl, $actionText, $steps ([title, text]...),
|            $showSteps (bool), $supportEmail, $footerLinks (label => url)
--}}
@php
    $isRtl = ($locale ?? app()->getLocale()) === 'ar';
    $dir = $isRtl ? 'rtl' : 'ltr';
    $align = $isRtl ? 'right' : 'left';
    $accentSide = $isRtl ? 'right' : 'left';

    $sans = "'Segoe UI', Tahoma, Arial, sans-serif";
    $serif = "Georgia, 'Times New Roman', serif";
    $mono = "'Courier New', Consolas, monospace";

    // Palette (solid colours only, so every email client renders it the same)
    $void = '#04122a';     // page background
    $navy = '#0b2c58';     // brand navy
    $deep = '#08234a';     // card gradient end
    $glass = '#103666';    // raised panels
    $edge = '#22497f';     // panel borders
    $blue = '#1588c7';
    $sky = '#38bdf8';
    $ice = '#93c5fd';
    $soft = '#c7dcf5';

    $logoUrl = config('app.logo_url', 'https://raw.githubusercontent.com/Abdelrahman5884/Motafawweq-Frontend/main/public/logo-light.png');
    $codeChars = !empty($code) ? preg_split('//u', trim($code), -1, PREG_SPLIT_NO_EMPTY) : [];
    $supportEmail = $supportEmail ?? config('app.support_email');

    $bars = [
        ['h' => 8,  'c' => '#1d4f8f'],
        ['h' => 14, 'c' => '#1b66ad'],
        ['h' => 22, 'c' => $blue],
        ['h' => 31, 'c' => '#2ba3dd'],
        ['h' => 42, 'c' => $sky],
    ];

    $defaultSteps = $isRtl
        ? [
            ['انسخ الرمز', 'اضغط زر النسخ أو حدّد الرمز وانسخه.'],
            ['ارجع للتحقق', 'افتح صفحة التحقق في متفوّق.'],
            ['الصق وأكمل', 'أدخل الرمز وأنهِ العملية.'],
        ]
        : [
            ['Copy the code', 'Tap copy, or select the code and copy it.'],
            ['Go back', 'Open the verification page in Motafawweq.'],
            ['Paste & finish', 'Enter the code and complete the step.'],
        ];
    $stepsList = !empty($steps) ? $steps : (!empty($codeChars) && ($showSteps ?? true) ? $defaultSteps : []);
    $stepsList = array_slice($stepsList, 0, 3);
@endphp
<!DOCTYPE html>
<html lang="{{ $isRtl ? 'ar' : 'en' }}" dir="{{ $dir }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="x-apple-disable-message-reformatting">
    <meta name="color-scheme" content="dark light">
    <meta name="supported-color-schemes" content="dark light">
    <title>{{ $title ?? 'Motafawweq' }}</title>
    <style>
        body, table, td, a { -webkit-text-size-adjust: 100%; -ms-text-size-adjust: 100%; }
        table, td { mso-table-lspace: 0pt; mso-table-rspace: 0pt; }
        img { -ms-interpolation-mode: bicubic; border: 0; outline: none; }
        a { text-decoration: none; }

        @@media only screen and (max-width: 620px) {
            .px     { padding-left: 20px !important; padding-right: 20px !important; }
            .h1     { font-size: 24px !important; }
            .digit  { width: 38px !important; height: 50px !important; font-size: 24px !important; }
            .col    { display: block !important; width: 100% !important; padding: 0 0 10px 0 !important; }
            .col-in { text-align: {{ $align }} !important; }
        }
    </style>
</head>
<body style="margin: 0; padding: 0; width: 100%; background-color: {{ $void }}; font-family: {{ $sans }}; direction: {{ $dir }};">

    @if(!empty($intro))
        <div style="display: none; max-height: 0; overflow: hidden; opacity: 0; mso-hide: all; font-size: 1px; line-height: 1px; color: {{ $void }};">
            {{ \Illuminate\Support\Str::limit(strip_tags($intro), 110) }}
            &#847;&zwnj;&nbsp;&#847;&zwnj;&nbsp;&#847;&zwnj;&nbsp;&#847;&zwnj;&nbsp;&#847;&zwnj;&nbsp;
        </div>
    @endif

    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" bgcolor="{{ $void }}" style="background-color: {{ $void }};">
        <tr>
            <td align="center" style="padding: 34px 12px;">

                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" bgcolor="{{ $navy }}" style="max-width: 620px; background-color: {{ $navy }}; background-image: linear-gradient(180deg, {{ $navy }} 0%, {{ $deep }} 100%); border: 1px solid {{ $edge }}; border-radius: 28px; overflow: hidden;">

                    {{-- top sky edge --}}
                    <tr>
                        <td style="height: 5px; line-height: 5px; font-size: 5px; background-color: {{ $sky }}; background-image: linear-gradient(90deg, {{ $blue }}, {{ $sky }}, {{ $blue }});">&nbsp;</td>
                    </tr>

                    {{-- ═════════ HERO ═════════ --}}
                    <tr>
                        <td align="center" class="px" style="padding: 46px 40px 8px 40px; text-align: center;">

                            {{-- concentric rings around the logo --}}
                            <table role="presentation" cellspacing="0" cellpadding="0" border="0" align="center" style="margin: 0 auto;">
                                <tr>
                                    <td align="center" style="border: 1px solid #1a4274; border-radius: 50%; padding: 14px;">
                                        <table role="presentation" cellspacing="0" cellpadding="0" border="0" align="center">
                                            <tr>
                                                <td align="center" style="border: 2px solid {{ $blue }}; border-radius: 50%; padding: 10px;">
                                                    <table role="presentation" cellspacing="0" cellpadding="0" border="0" align="center">
                                                        <tr>
                                                            <td align="center" valign="middle" style="background-color: #ffffff; border-radius: 50%; padding: 16px; border-bottom: 4px solid {{ $sky }};">
                                                                <img src="{{ $logoUrl }}" alt="Motafawweq" width="46" height="46" style="display: block; width: 46px; height: 46px; object-fit: contain;">
                                                            </td>
                                                        </tr>
                                                    </table>
                                                </td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                            </table>

                            <div dir="ltr" style="margin-top: 22px; font-family: {{ $serif }}; font-size: 20px; letter-spacing: 9px; color: #ffffff; text-indent: 9px;">MOTAFAWWEQ</div>

                            <table role="presentation" cellspacing="0" cellpadding="0" border="0" align="center" style="margin: 12px auto 10px auto;">
                                <tr>
                                    <td width="22" style="height: 2px; line-height: 2px; font-size: 2px; background-color: {{ $blue }};">&nbsp;</td>
                                    <td width="8" style="font-size: 2px;">&nbsp;</td>
                                    <td width="22" style="height: 2px; line-height: 2px; font-size: 2px; background-color: {{ $sky }};">&nbsp;</td>
                                </tr>
                            </table>

                            <div style="font-family: {{ $sans }}; font-size: 12px; letter-spacing: {{ $isRtl ? '0' : '3px' }}; color: {{ $ice }};">
                                {{ $isRtl ? 'رحلتك نحو التفوق' : 'EDUCATION & EXCELLENCE' }}
                            </div>

                            @if(!empty($badge))
                                <table role="presentation" cellspacing="0" cellpadding="0" border="0" align="center" style="margin: 30px auto 0 auto;">
                                    <tr>
                                        <td style="background-color: {{ $glass }}; border: 1px solid {{ $sky }}; border-radius: 999px; padding: 7px 20px; font-family: {{ $sans }}; font-size: 12.5px; font-weight: 700; color: {{ $sky }};">
                                            {{ $badge }}
                                        </td>
                                    </tr>
                                </table>
                            @endif

                            <h1 class="h1" style="margin: {{ !empty($badge) ? '18px' : '34px' }} 0 0 0; text-align: center; font-family: {{ $isRtl ? $sans : $serif }}; font-size: 30px; font-weight: {{ $isRtl ? '700' : '400' }}; color: #ffffff; line-height: 1.45;">
                                {{ $title }}
                            </h1>
                        </td>
                    </tr>

                    {{-- ═════════ GREETING + INTRO ═════════ --}}
                    <tr>
                        <td dir="{{ $dir }}" class="px" style="padding: 26px 44px 6px 44px; direction: {{ $dir }};">
                            <p style="margin: 0 0 10px 0; font-family: {{ $sans }}; font-size: 17px; font-weight: 700; color: #ffffff; text-align: {{ $align }};">
                                {{ $greeting }}
                            </p>
                            <p style="margin: 0; font-family: {{ $sans }}; font-size: 15px; line-height: 1.95; color: {{ $soft }}; text-align: {{ $align }};">
                                {{ $intro }}
                            </p>
                        </td>
                    </tr>

                    {{-- ═════════ CODE ═════════ --}}
                    @if(!empty($codeChars))
                        <tr>
                            <td class="px" style="padding: 26px 32px 0 32px;">
                                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" bgcolor="{{ $glass }}" style="background-color: {{ $glass }}; border: 1px solid {{ $edge }}; border-radius: 22px; border-collapse: separate;">
                                    <tr>
                                        <td align="center" style="padding: 26px 14px 8px 14px; text-align: center; font-family: {{ $sans }}; font-size: 13px; font-weight: 600; color: {{ $ice }};">
                                            {{ $isRtl ? 'رمز التحقق الخاص بك' : 'Your verification code' }}
                                        </td>
                                    </tr>
                                    <tr>
                                        <td align="center" style="padding: 12px 12px 22px 12px; text-align: center;">
                                            <table role="presentation" dir="ltr" cellspacing="0" cellpadding="0" border="0" align="center" style="margin: 0 auto; border-collapse: separate; border-spacing: 7px 0;">
                                                <tr>
                                                    @foreach($codeChars as $char)
                                                        <td align="center" valign="middle" class="digit" width="46" height="60" bgcolor="#ffffff" style="width: 46px; height: 60px; background-color: #ffffff; border-radius: 12px; border-bottom: 5px solid {{ $sky }}; font-family: {{ $mono }}; font-size: 30px; font-weight: 700; color: {{ $navy }}; text-align: center; user-select: all; -webkit-user-select: all;">{{ $char }}</td>
                                                    @endforeach
                                                </tr>
                                            </table>
                                        </td>
                                    </tr>
                                    <tr>
                                        <td align="center" style="padding: 0 20px 26px 20px; text-align: center;">
                                            @if(!empty($copyUrl))
                                                <table role="presentation" cellspacing="0" cellpadding="0" border="0" align="center" style="margin: 0 auto 14px auto;">
                                                    <tr>
                                                        <td align="center" bgcolor="{{ $sky }}" style="background-color: {{ $sky }}; background-image: linear-gradient(90deg, {{ $sky }}, #7dd3fc); border-radius: 12px;">
                                                            <a href="{{ $copyUrl }}" target="_blank" style="display: inline-block; padding: 14px 36px; font-family: {{ $sans }}; font-size: 15px; font-weight: 700; color: {{ $navy }}; text-decoration: none;">
                                                                {{ $isRtl ? 'نسخ الرمز وتعبئته تلقائياً' : 'Copy & auto-fill code' }}
                                                            </a>
                                                        </td>
                                                    </tr>
                                                </table>
                                            @endif

                                            <div style="font-family: {{ $sans }}; font-size: 12.5px; line-height: 1.8; color: {{ $ice }};">
                                                {{ $isRtl ? 'أو اضغط على الرمز مرتين لتحديده ثم انسخه' : 'Or double-tap the code to select it, then copy' }}
                                            </div>

                                            @if(!empty($ttlMinutes))
                                                <div style="margin-top: 12px; font-family: {{ $sans }}; font-size: 12.5px; font-weight: 700; color: {{ $sky }};">
                                                    <span style="color: {{ $sky }};">&#9679;</span>
                                                    {{ $isRtl ? "ينتهي الرمز خلال {$ttlMinutes} دقائق" : "Code expires in {$ttlMinutes} minutes" }}
                                                </div>
                                            @endif
                                        </td>
                                    </tr>
                                </table>
                            </td>
                        </tr>
                    @endif

                    {{-- ═════════ STEPS (3 columns) ═════════ --}}
                    @if(!empty($stepsList))
                        <tr>
                            <td class="px" style="padding: 20px 32px 0 32px;">
                                <table role="presentation" dir="{{ $dir }}" width="100%" cellspacing="0" cellpadding="0" border="0" style="border-collapse: separate; border-spacing: 0;">
                                    <tr>
                                        @foreach($stepsList as $i => $step)
                                            <td class="col" width="33%" valign="top" style="width: 33.33%; padding: 0 {{ $loop->last ? '0' : ($isRtl ? '0 0 6px' : '6px 0 0') }};">
                                                @php /* spacing handled by inner margin instead */ @endphp
                                                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" bgcolor="{{ $glass }}" style="background-color: {{ $glass }}; border: 1px solid {{ $edge }}; border-radius: 16px; border-collapse: separate;">
                                                    <tr>
                                                        <td class="col-in" align="{{ $align }}" style="padding: 16px 14px 18px 14px; text-align: {{ $align }};">
                                                            <table role="presentation" cellspacing="0" cellpadding="0" border="0">
                                                                <tr>
                                                                    <td align="center" valign="middle" width="28" height="28" style="width: 28px; height: 28px; border-radius: 50%; background-color: {{ $sky }}; font-family: {{ $sans }}; font-size: 13px; font-weight: 700; color: {{ $navy }}; text-align: center;">{{ $i + 1 }}</td>
                                                                </tr>
                                                            </table>
                                                            <div style="margin-top: 10px; font-family: {{ $sans }}; font-size: 14px; font-weight: 700; color: #ffffff;">{{ $step[0] ?? '' }}</div>
                                                            <div style="margin-top: 4px; font-family: {{ $sans }}; font-size: 12.5px; line-height: 1.7; color: {{ $ice }};">{{ $step[1] ?? '' }}</div>
                                                        </td>
                                                    </tr>
                                                </table>
                                            </td>
                                        @endforeach
                                    </tr>
                                </table>
                            </td>
                        </tr>
                    @endif

                    {{-- ═════════ DETAILS ═════════ --}}
                    @if(!empty($details) && is_array($details))
                        <tr>
                            <td class="px" style="padding: 20px 32px 0 32px;">
                                <table role="presentation" dir="{{ $dir }}" width="100%" cellspacing="0" cellpadding="0" border="0" bgcolor="{{ $glass }}" style="background-color: {{ $glass }}; border: 1px solid {{ $edge }}; border-radius: 18px; border-collapse: separate;">
                                    @foreach($details as $label => $value)
                                        <tr>
                                            <td style="padding: 14px 20px; {{ !$loop->last ? "border-bottom: 1px solid {$edge};" : '' }} font-family: {{ $sans }}; font-size: 13px; font-weight: 700; color: {{ $ice }}; width: 38%; text-align: {{ $align }};">
                                                {{ $label }}
                                            </td>
                                            <td dir="ltr" style="padding: 14px 20px; {{ !$loop->last ? "border-bottom: 1px solid {$edge};" : '' }} font-family: {{ $sans }}; font-size: 13px; font-weight: 600; color: #ffffff; text-align: {{ $align }};">
                                                {{ $value }}
                                            </td>
                                        </tr>
                                    @endforeach
                                </table>
                            </td>
                        </tr>
                    @endif

                    {{-- ═════════ ACTION ═════════ --}}
                    @if(!empty($actionUrl) && !empty($actionText))
                        <tr>
                            <td align="center" style="padding: 28px 32px 0 32px; text-align: center;">
                                <table role="presentation" cellspacing="0" cellpadding="0" border="0" align="center">
                                    <tr>
                                        <td align="center" bgcolor="{{ $blue }}" style="background-color: {{ $blue }}; background-image: linear-gradient(90deg, {{ $blue }}, {{ $sky }}); border-radius: 14px;">
                                            <a href="{{ $actionUrl }}" target="_blank" style="display: inline-block; padding: 16px 46px; font-family: {{ $sans }}; font-size: 15.5px; font-weight: 700; color: #ffffff; text-decoration: none;">
                                                {{ $actionText }}
                                            </a>
                                        </td>
                                    </tr>
                                </table>
                            </td>
                        </tr>
                    @endif

                    {{-- ═════════ SECURITY NOTE ═════════ --}}
                    @if(!empty($securityNote))
                        <tr>
                            <td class="px" style="padding: 24px 32px 0 32px;">
                                <table role="presentation" dir="{{ $dir }}" width="100%" cellspacing="0" cellpadding="0" border="0" bgcolor="#0d3a66" style="background-color: #0d3a66; border: 1px solid #1d5d94; border-{{ $accentSide }}: 4px solid {{ $sky }}; border-radius: 14px; border-collapse: separate;">
                                    <tr>
                                        <td width="26" valign="top" style="padding: 16px {{ $isRtl ? '16px 16px 0' : '0 16px 16px' }};">
                                            <table role="presentation" cellspacing="0" cellpadding="0" border="0">
                                                <tr>
                                                    <td align="center" valign="middle" width="24" height="24" style="width: 24px; height: 24px; border-radius: 50%; background-color: {{ $sky }}; font-family: {{ $serif }}; font-size: 14px; font-weight: 700; color: {{ $navy }}; text-align: center;">!</td>
                                                </tr>
                                            </table>
                                        </td>
                                        <td valign="top" style="padding: 15px 18px; font-family: {{ $sans }}; font-size: 13.5px; line-height: 1.85; color: {{ $soft }}; text-align: {{ $align }};">
                                            {{ $securityNote }}
                                        </td>
                                    </tr>
                                </table>
                            </td>
                        </tr>
                    @endif

                    {{-- ═════════ SUPPORT ═════════ --}}
                    @if(!empty($supportEmail))
                        <tr>
                            <td dir="{{ $dir }}" align="center" style="padding: 26px 32px 0 32px; text-align: center; font-family: {{ $sans }}; font-size: 13px; line-height: 1.8; color: {{ $ice }};">
                                {{ $isRtl ? 'محتاج مساعدة؟ راسلنا على' : 'Need a hand? Write to us at' }}
                                <a href="mailto:{{ $supportEmail }}" style="font-weight: 700; color: {{ $sky }}; text-decoration: none;">{{ $supportEmail }}</a>
                            </td>
                        </tr>
                    @endif

                    {{-- ═════════ FOOTER ═════════ --}}
                    <tr>
                        <td align="center" bgcolor="{{ $void }}" style="background-color: {{ $void }}; margin-top: 30px; padding: 30px 32px 26px 32px; text-align: center; border-top: 1px solid {{ $edge }};">

                            <table role="presentation" dir="{{ $dir }}" cellspacing="0" cellpadding="0" border="0" align="center" style="margin: 0 auto 14px auto; border-collapse: separate; border-spacing: 3px 0;">
                                <tr>
                                    @foreach($bars as $bar)
                                        <td valign="bottom" width="6" style="width: 6px;">
                                            <div style="width: 6px; height: {{ (int) round($bar['h'] * 0.6) }}px; line-height: {{ (int) round($bar['h'] * 0.6) }}px; font-size: 1px; background-color: {{ $bar['c'] }}; border-radius: 2px 2px 0 0;">&nbsp;</div>
                                        </td>
                                    @endforeach
                                </tr>
                            </table>

                            <div dir="ltr" style="font-family: {{ $serif }}; font-size: 13px; letter-spacing: 6px; color: #ffffff; text-indent: 6px;">MOTAFAWWEQ</div>

                            @if(!empty($footerLinks) && is_array($footerLinks))
                                <p dir="{{ $dir }}" style="margin: 14px 0 0 0; font-family: {{ $sans }}; font-size: 12.5px; line-height: 2; text-align: center;">
                                    @foreach($footerLinks as $label => $url)
                                        <a href="{{ $url }}" target="_blank" style="color: {{ $soft }}; font-weight: 600; text-decoration: none;">{{ $label }}</a>
                                        @if(!$loop->last)<span style="color: {{ $blue }};">&nbsp;&nbsp;&bull;&nbsp;&nbsp;</span>@endif
                                    @endforeach
                                </p>
                            @endif

                            <p dir="{{ $dir }}" style="margin: 16px 0 4px 0; font-family: {{ $sans }}; font-size: 11.5px; line-height: 1.8; color: #6f93c2;">
                                {{ $isRtl ? 'هذا إيميل تلقائي، من فضلك لا ترد عليه مباشرة.' : 'This is an automated email, please do not reply to it directly.' }}
                            </p>
                            <p dir="ltr" style="margin: 0; font-family: {{ $sans }}; font-size: 11.5px; color: #6f93c2;">
                                &copy; {{ date('Y') }} Motafawweq. All rights reserved.
                            </p>
                        </td>
                    </tr>

                </table>

            </td>
        </tr>
    </table>
</body>
</html>