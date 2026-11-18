<?php

namespace App\Console\Commands;

use App\Models\CalendarAccount;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Estado del servicio, sin datos personales (el registro del diagnóstico puede ser público):
 * cuándo corrió por última vez cada tarea, y por cuenta la última sincronización, el último error y cuántos correos llegaron.
 */
class Status extends Command
{
    protected $signature = 'smartgenda:estado';

    protected $description = 'Muestra si las tareas programadas y las cuentas están funcionando (sin datos personales)';

    public function handle(): int
    {
        $this->line('Ahora (UTC): '.now()->utc()->toDateTimeString());
        $this->line('Programador (schedule:run) visto por última vez: '.(Cache::get('smartgenda:heartbeat') ?? 'nunca'));
        $this->line('Correo leído por última vez (correo:resumir): '.(Cache::get('smartgenda:mail-run') ?? 'sin registro'));

        foreach (CalendarAccount::query()->withCount([
            'emailItems as items_24h' => fn ($q) => $q->where('created_at', '>=', now()->subDay()),
            'emailItems as items_7d' => fn ($q) => $q->where('created_at', '>=', now()->subDays(7)),
        ])->orderBy('id')->get() as $a) {
            $this->line(sprintf(
                "#%d %s · correo %s · agenda sincronizada %s · correo leído %s · correos nuevos 24 h: %d, 7 días: %d · último recibido %s\n   error agenda: %s\n   error correo: %s",
                $a->id, $a->provider, $a->mail_enabled ? 'activo' : 'apagado',
                $a->last_synced_at?->utc()->toDateTimeString() ?? 'nunca', $a->mail_synced_at?->utc()->toDateTimeString() ?? 'nunca',
                $a->items_24h, $a->items_7d, $a->emailItems()->max('received_at') ?? '—',
                self::redact($a->last_error), self::redact($a->mail_error),
            ));
        }

        return self::SUCCESS;
    }

    /** Quita correos, tokens y números largos de un mensaje de error. */
    public static function redact(?string $text): string
    {
        if (blank($text)) {
            return '—';
        }
        $text = preg_replace('/[\w.+-]+@[\w-]+(\.[\w-]+)+/u', '<correo>', $text);
        $text = preg_replace('/[A-Za-z0-9_\-]{24,}/', '<token>', $text);

        return mb_strimwidth(preg_replace('/\s+/', ' ', $text), 0, 300, '…');
    }
}
