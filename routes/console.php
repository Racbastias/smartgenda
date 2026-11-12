<?php

use Illuminate\Support\Facades\Schedule;

// En Hostinger: cron cada minuto con `php artisan schedule:run` (ver README).
Schedule::command('agenda:sync')->everyTwoMinutes()->withoutOverlapping();
Schedule::command('correo:resumir')->everyTwoMinutes()->withoutOverlapping();
