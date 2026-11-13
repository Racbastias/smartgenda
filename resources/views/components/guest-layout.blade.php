@props(['title' => 'Smartgenda'])
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#0E1116">
    <title>{{ $title }} · Smartgenda</title>
    <link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Inter+Tight:wght@600;700;800&display=swap">
    <link rel="stylesheet" href="{{ asset('css/app.css') }}?v={{ filemtime(public_path('css/app.css')) }}">
</head>
<body class="guest-body">
<main class="guest">
    <section class="guest-hero" aria-hidden="true">
        <div class="brand"><span class="brand-mark">S</span> Smartgenda</div>
        <p class="guest-title">Tu día,<br>en orden<span>.</span></p>
        <p class="guest-sub">Tus tres agendas y el correo que pide tu acción, en un solo lugar.</p>
        <ul class="guest-cals">
            <li><span style="background:#D97706"></span>Lilienfeld</li>
            <li><span style="background:#2563EB"></span>Aminfo</li>
            <li><span style="background:#059669"></span>Personal</li>
        </ul>
    </section>
    <section class="guest-card">
        @if (session('status'))<div class="flash" role="status">{{ session('status') }}</div>@endif
        @if ($errors->any())<div class="flash flash-error" role="alert">{{ $errors->first() }}</div>@endif
        {{ $slot }}
    </section>
</main>
</body>
</html>
