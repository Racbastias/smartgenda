<?php

use App\Support\Assistant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schedule;

// En Hostinger: cron cada minuto con `php artisan schedule:run` (ver README).
// Jornada laboral (lunes a viernes, 08:30 a 18:00): cada minuto. Fuera de ese horario: una vez por hora.
// El candado de withoutOverlapping vence a los 10 minutos: si el hosting corta un proceso a medias, no deja la tarea
// detenida por 24 horas (el valor por defecto).
$toca = fn (): bool => Assistant::workHours() || Carbon::now(config('app.work_timezone'))->minute === 0;

// Hostinger no permite proc_open en el cron, así que las tareas corren dentro del mismo proceso
// (Schedule::call + Artisan::call) en vez de lanzar un proceso aparte como hace Schedule::command.
$tarea = fn (string $comando) => fn () => Artisan::call($comando);

Schedule::call($tarea('agenda:sync'))->name('agenda:sync')->everyMinute()->when($toca)->withoutOverlapping(10);
Schedule::call($tarea('correo:resumir'))->name('correo:resumir')->everyMinute()->when($toca)->withoutOverlapping(10);

// Aviso de reuniones (siempre cada minuto: es solo una consulta a la base)
Schedule::call($tarea('agenda:avisar'))->name('agenda:avisar')->everyMinute()->withoutOverlapping(5);

// Latido: deja registro de que el cron está corriendo (lo muestra `smartgenda:estado`)
Schedule::call(fn () => Cache::forever('smartgenda:heartbeat', now()->utc()->toDateTimeString()))->name('latido')->everyMinute();
