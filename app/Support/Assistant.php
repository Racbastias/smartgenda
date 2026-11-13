<?php

namespace App\Support;

use Carbon\CarbonImmutable;

/** Saludo y frase del día en "Hoy": el tono de un asistente que conoce tu trabajo. */
class Assistant
{
    private const CALM = [
        'Bandeja bajo control: ningún cliente esperando tu respuesta.',
        'Sin cotizaciones pendientes por ahora. Buen momento para adelantar renovaciones.',
        'Todo al día. Si llega algo urgente de un cliente, te lo dejo arriba de la lista.',
        'Correo en orden. Aprovecha de llamar a ese cliente que tienes en mente.',
        'Nada que apagar por ahora: día para avanzar con lo importante.',
    ];

    /**
     * @param  array{accion: int, alta: int, seguimiento: int, leer: int}  $counts
     * @return array{icon: string, hello: string, line: string}
     */
    public static function greeting(string $name, CarbonImmutable $now, array $counts, ?array $next, bool $workHours): array
    {
        $first = strtok($name, ' ') ?: $name;
        [$icon, $hello] = match (true) {
            $now->hour < 12 && $now->hour >= 5 => ['coffee', "Buenos días, {$first}"],
            $now->hour < 20 && $now->hour >= 12 => ['sun', "Buenas tardes, {$first}"],
            default => ['moon', "Buenas noches, {$first}"],
        };

        // Los números y la próxima reunión se muestran como tarjetas; aquí va solo el tono del día.
        $parts = [match (true) {
            $counts['alta'] > 0 => 'Hay temas con prioridad alta: parte por ahí.',
            $counts['accion'] > 0 && $now->hour < 12 => 'Mientras sale el café, esto es lo que tienes.',
            $counts['accion'] > 0 => 'Esto es lo que te queda por mover hoy.',
            default => self::CALM[$now->dayOfYear % count(self::CALM)],
        }];
        if (! $workHours) {
            $parts[] = 'Fuera de horario reviso el correo cada hora.';
        }

        return ['icon' => $icon, 'hello' => $hello, 'line' => implode(' ', $parts)];
    }

    /** Lunes a viernes, 08:30 a 18:00 (zona de la jornada). */
    public static function workHours(?CarbonImmutable $now = null): bool
    {
        $now = ($now ?? CarbonImmutable::now())->setTimezone(config('app.work_timezone'));
        $minutes = $now->hour * 60 + $now->minute;

        return $now->isWeekday() && $minutes >= 8 * 60 + 30 && $minutes <= 18 * 60;
    }
}
