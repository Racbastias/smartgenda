<?php

use App\Support\Assistant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schedule;

// En Hostinger: cron cada minuto con `php artisan schedule:run` (ver README).
// Jornada laboral (lunes a viernes, 08:30 a 18:00): cada minuto. Fuera de ese horario: una vez por hora.
$toca = fn (): bool => Assistant::workHours() || Carbon::now(config('app.work_timezone'))->minute === 0;

Schedule::command('agenda:sync')->everyMinute()->when($toca)->withoutOverlapping();
Schedule::command('correo:resumir')->everyMinute()->when($toca)->withoutOverlapping();
