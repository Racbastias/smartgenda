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

        $parts = [];
        if ($counts['alta'] > 0) {
            $parts[] = $counts['alta'] === 1
                ? 'Hay 1 tema con prioridad alta: parte por ahí.'
                : "Hay {$counts['alta']} temas con prioridad alta: parte por ahí.";
        } elseif ($counts['accion'] > 0) {
            $parts[] = $now->hour < 12
                ? ($counts['accion'] === 1 ? 'Mientras sale el café: 1 correo espera tu acción.' : "Mientras sale el café: {$counts['accion']} correos esperan tu acción.")
                : ($counts['accion'] === 1 ? '1 correo espera tu acción.' : "{$counts['accion']} correos esperan tu acción.");
        } else {
            $parts[] = self::CALM[$now->dayOfYear % count(self::CALM)];
        }
        if ($counts['seguimiento'] > 0) {
            $parts[] = $counts['seguimiento'] === 1 ? 'Tienes 1 respuesta por perseguir.' : "Tienes {$counts['seguimiento']} respuestas por perseguir.";
        }
        if ($next) {
            $parts[] = "Tu próxima reunión: {$next['title']} a las {$next['time']}.";
        }
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
