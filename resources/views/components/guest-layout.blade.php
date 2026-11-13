@props(['title' => 'Smartgenda'])
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#0E1116">
    <x-head-icons/>
    <title>{{ $title }} · Smartgenda</title>
    <link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Inter+Tight:wght@600;700;800&display=swap">
    <link rel="stylesheet" href="{{ asset('css/app.css') }}?v={{ filemtime(public_path('css/app.css')) }}">
</head>
<body class="guest-body">
<main class="guest">
    <section class="guest-hero">
        <div class="brand"><img class="brand-mark" src="{{ asset('img/logo-mark.png') }}" alt=""> Smartgenda</div>
        <p class="guest-eyebrow">Asistente y agenda inteligente</p>
        <p class="guest-title">Tu día,<br>en orden<span>.</span></p>
        <p class="guest-sub">Junta todas tus agendas en una sola línea del día y lee tu correo para decirte qué requiere tu acción.</p>
        <ul class="guest-feats">
            <li><x-icon name="calendar"/>Todas tus agendas, sin choques</li>
            <li><x-icon name="mail"/>El correo que importa, resumido</li>
            <li><x-icon name="zap"/>Seguimiento de lo pendiente</li>
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
