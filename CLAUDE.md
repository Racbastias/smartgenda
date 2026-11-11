# Smartgenda

Laravel 13 (PHP 8.3), Blade sin build de frontend (FullCalendar servido desde `public/vendor`). UI y mensajes en español.
Se publica en hosting compartido de Hostinger: nada de Node en producción, ni colas persistentes; las tareas periódicas van por `routes/console.php` + cron `schedule:run`.

- Cada agenda externa implementa `App\Calendar\CalendarProvider`. Fechas siempre en UTC en la base.
- Las credenciales van en `calendar_accounts.secret` (cast `encrypted`).
- Multiusuario: todo acceso a agendas/eventos se filtra por el usuario autenticado.
- Tests: `php artisan test` (sqlite en memoria; los proveedores se reemplazan con `Tests\Fakes\FakeCalendarProvider` o `Http::fake`).
