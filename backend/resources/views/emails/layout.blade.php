<!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>{{ $brand['name'] ?? 'ТЕТА' }}</title>
</head>
<body style="margin:0;padding:0;background:#F4F4F7;font-family:Onest,'Helvetica Neue',Arial,sans-serif;color:#242424;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#F4F4F7;padding:24px 12px;">
  <tr><td align="center">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;background:#FFFFFF;border-radius:16px;overflow:hidden;">
      <tr><td style="padding:24px 32px;border-bottom:1px solid #E6E5EC;">
        @if(!empty($brand['logo_url']))
          <img src="{{ $brand['logo_url'] }}" alt="{{ $brand['name'] ?? 'ТЕТА' }}" height="32" style="display:block;height:32px;">
        @else
          <span style="font-size:20px;font-weight:700;color:{{ $brand['palette']['brand'] ?? '#4D427A' }};">{{ $brand['name'] ?? 'ТЕТА' }}</span>
        @endif
      </td></tr>
      <tr><td style="padding:28px 32px;font-size:16px;line-height:1.6;">
        {!! $body !!}
        @if($actionUrl)
          <p style="margin:28px 0 8px;">
            <a href="{{ $actionUrl }}" style="display:inline-block;background:{{ $brand['palette']['brand'] ?? '#4D427A' }};color:#FFFFFF;text-decoration:none;padding:12px 24px;border-radius:12px;font-weight:600;">{{ $actionText ?? 'Открыть' }}</a>
          </p>
        @endif
      </td></tr>
      <tr><td style="padding:20px 32px;background:#F4F4F7;font-size:13px;line-height:1.5;color:#6E6E6E;">
        @if($footerHtml){!! $footerHtml !!}<br>@endif
        {{ $brand['legal_name'] ?? 'ТЕТА' }} · <a href="{{ $brand['site_url'] ?? config('app.frontend_url') }}" style="color:#6E6E6E;">{{ $brand['domain'] ?? 'teta.su' }}</a><br>
        Платформа не оказывает экстренную помощь. При угрозе жизни звоните 112.
      </td></tr>
    </table>
  </td></tr>
</table>
</body>
</html>
