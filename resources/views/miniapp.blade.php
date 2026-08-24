<!DOCTYPE html>
<html lang="uz">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <title>Lexible</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Baloo+2:wght@600;700;800&family=Nunito:wght@400;600;700;800&display=swap" rel="stylesheet">

    {{-- Telegram requires this script to be loaded from their own domain. --}}
    <script src="https://telegram.org/js/telegram-web-app.js?58"></script>
    <script>window.LEXIBLE = @json($config);</script>

    @foreach ($assets['css'] as $stylesheet)
        <link rel="stylesheet" href="{{ $stylesheet }}">
    @endforeach
</head>
<body>
    <div id="app"></div>

    @if ($assets['js'])
        <script type="module" src="{{ $assets['js'] }}"></script>
    @else
        <p style="font-family:system-ui;padding:24px;text-align:center">
            Interfeys hali yigʼilmagan — frontend repozitoriyasida
            <code>npm run build</code> ni ishga tushiring.
        </p>
    @endif
</body>
</html>
