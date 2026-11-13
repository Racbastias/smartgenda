<?php

use App\Support\Assistant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schedule;

// En Hostinger: cron cada minuto con `php artisan schedule:run` (ver README).
// Jornada laboral (lunes a viernes, 08:30 a 18:00): cada minuto. Fuera de ese horario: una vez por hora.
// El candado de withoutOverlapping vence a los 10 minutos: si el hosting corta un proceso a medias, no deja la tarea
// detenida por 24 horas (el valor por defecto).
$toca = fn (): bool => Assistant::workHours() || Carbon::now(config('app.work_timezone'))->minute === 0;

Schedule::command('agenda:sync')->everyMinute()->when($toca)->withoutOverlapping(10);
Schedule::command('correo:resumir')->everyMinute()->when($toca)->withoutOverlapping(10);

// Aviso de reuniones (siempre cada minuto: es solo una consulta a la base)
Schedule::command('agenda:avisar')->everyMinute()->withoutOverlapping(5);
