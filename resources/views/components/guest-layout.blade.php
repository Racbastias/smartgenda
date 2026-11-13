@props(['title' => 'Smartgenda'])
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }} · Smartgenda</title>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Inter+Tight:wght@600;700;800&display=swap">
    <link rel="stylesheet" href="{{ asset('css/app.css') }}?v={{ filemtime(public_path('css/app.css')) }}">
</head>
<body>
<main class="guest">
    <div class="card card-pad stack">
        <div class="brand" style="padding:0 0 4px"><span class="brand-mark">S</span> Smartgenda</div>
        @if (session('status'))<div class="flash" role="status">{{ session('status') }}</div>@endif
        @if ($errors->any())<div class="flash flash-error" role="alert">{{ $errors->first() }}</div>@endif
        {{ $slot }}
    </div>
</main>
</body>
</html>
