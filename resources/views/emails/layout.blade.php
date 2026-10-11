<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $subject }}</title>
</head>
<body style="margin:0;padding:0;background:#F8FAFC;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#F8FAFC;margin:0;padding:0;">
        <tr>
            <td align="center" style="padding:16px;">
                <table role="presentation" width="600" cellpadding="0" cellspacing="0" style="width:100%;max-width:600px;background:#FFFFFF;border:1px solid #E2E8F0;border-radius:12px;">
                    <tr>
                        <td style="padding:24px 16px 8px 16px;font-family:system-ui,Segoe UI,sans-serif;font-size:20px;line-height:28px;font-weight:600;color:#0F172A;">
                            {{ $institution_name }}
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:8px 16px 24px 16px;font-family:system-ui,Segoe UI,sans-serif;font-size:14px;line-height:22px;color:#0F172A;">
                            @yield('content')
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:16px;border-top:1px solid #E2E8F0;font-family:system-ui,Segoe UI,sans-serif;font-size:12px;line-height:18px;color:#64748B;">
                            {{ $footer }}
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
