<?php

namespace App\Inbox;

use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Pendientes sin IA (sin costo): detecta pedidos, preguntas y fechas con reglas simples.
 * Se usa cuando no hay ANTHROPIC_API_KEY. No redacta un resumen: muestra el inicio del correo.
 */
class RuleSummarizer implements EmailSummarizer
{
    /** Frases que suelen pedir algo a quien recibe el correo. */
    private const REQUEST = '/\b(necesito|necesitamos|necesitar[ií]a|por favor|favor\s+(de\s+)?\w+|podr[ií]as?|puedes|pueden|me (confirmas|env[ií]as|mandas|avisas|indicas)|'
        .'quedo (atento|atenta)|quedamos (atentos|atentas)|agradecer[ií]a|solicito|solicitamos|te pido|les pido|recuerda|recordatorio|'
        .'pendiente|plazo|fecha l[ií]mite|a m[aá]s tardar|antes del?|confirmar|enviar|revisar|aprobar|firmar|responder)\b/iu';

    private const URGENT = '/\b(urgente|urgencia|a la brevedad|lo antes posible|asap|hoy mismo|inmediato)\b/iu';

    /** Remitentes automáticos: boletines, notificaciones, sistemas. */
    private const AUTOMATED = '/(no-?reply|noreply|notificaci|notification|newsletter|boletin|mailer|bounce|postmaster|alertas?@|info@|marketing|news@)/i';

    /** Textos típicos de boletines y publicidad. */
    private const BULK_TEXT = '/(ver (este|el) (correo|mail|mensaje|e-?mail) en (tu|su|el) navegador|ver (correo|mail|mensaje) en (tu|su|el) navegador|'
        .'si no (puede|puedes|logra|logras) ver (este|el|bien este) (correo|mail|mensaje|e-?mail)|'
        .'darse de baja|date de baja|desuscrib|anular (la |tu |su )?suscripci|cancelar (la |tu |su )?suscripci|unsubscribe|view (this )?(email|in browser)|'
        .'dejar de recibir|no (responda|respondas) (a )?este (correo|mensaje)|correo (generado|enviado) autom[aá]ticamente)/iu';

    private const WEEKDAYS = ['domingo' => 0, 'lunes' => 1, 'martes' => 2, 'miercoles' => 3, 'jueves' => 4, 'viernes' => 5, 'sabado' => 6];

    private const MONTHS = ['enero' => 1, 'febrero' => 2, 'marzo' => 3, 'abril' => 4, 'mayo' => 5, 'junio' => 6, 'julio' => 7,
        'agosto' => 8, 'septiembre' => 9, 'setiembre' => 9, 'octubre' => 10, 'noviembre' => 11, 'diciembre' => 12];

    public function summarize(User $user, array $messages): array
    {
        $tz = $user->timezone ?: config('app.timezone');
        $out = [];
        foreach ($messages as $m) {
            $out[$m->remoteId] = $this->one($m, $m->receivedAt->setTimezone($tz)->startOfDay());
        }

        return $out;
    }

    private function one(MailMessage $m, CarbonImmutable $day): EmailSummary
    {
        $text = $this->ownText($m->body);
        $automated = $m->bulk
            || preg_match(self::AUTOMATED, (string) $m->fromEmail)
            || preg_match(self::BULK_TEXT, $m->body);
        $sentences = $this->sentences($text);

        $actions = [];
        if (! $automated && $m->directlyAddressed) {
            foreach ($sentences as $sentence) {
                if (preg_match(self::REQUEST, $sentence) || str_ends_with($sentence, '?')) {
                    $actions[] = ['text' => mb_strimwidth($sentence, 0, 180, '…'), 'due' => $this->dueDate($sentence, $day)];
                }
                if (count($actions) === 3) {
                    break;
                }
            }
        }

        $due = collect($actions)->pluck('due')->filter()->sort()->first();
        $urgent = preg_match(self::URGENT, $m->subject.' '.$text)
            || ($due && CarbonImmutable::parse($due)->lessThanOrEqualTo($day->addDays(2)));

        return new EmailSummary(
            summary: mb_strimwidth(implode(' ', array_slice($sentences, 0, 2)) ?: '(sin texto)', 0, 240, '…'),
            priority: $automated ? 'baja' : ($actions && $urgent ? 'alta' : ($actions ? 'media' : 'baja')),
            needsReply: ! $automated && $m->directlyAddressed && ! $m->replied && $actions !== [],
            actions: $m->replied ? [] : $actions,
        );
    }

    /** El texto nuevo del correo: sin saludo, sin el correo citado y sin la firma. */
    private function ownText(string $body): string
    {
        $lines = [];
        foreach (preg_split('/\R/u', $body) as $line) {
            $line = trim($line);
            if (preg_match('/^(-{2,}\s*(mensaje original|original message)|_{5,}|>|(el|on)\s.+(escribi[oó]|wrote):$|(de|from):\s)/iu', $line)
                || preg_match('/^--\s*$/', $line)) {
                break; // de aquí en adelante es correo citado o firma
            }
            if ($line === '' || preg_match('/^(hola|estimad[oa]s?|buen(os|as) (d[ií]as|tardes|noches)|querid[oa]s?|dear|hi)\b[^.?!]{0,40}[,:!]?$/iu', $line)) {
                continue;
            }
            if (preg_match('/^(saludos|atte\.?|atentamente|cordialmente|un abrazo|gracias[.!]?$|slds)/iu', $line)) {
                break;
            }
            $lines[] = $line;
        }

        return implode(' ', $lines);
    }

    /** @return list<string> */
    private function sentences(string $text): array
    {
        // Sin enlaces: no aportan al resumen y rompen el diseño
        $text = trim(preg_replace(['#\(?\s*(https?://|www\.)\S+\s*\)?#iu', '/[ \t]{2,}/'], ['', ' '], $text));

        $parts = preg_split('/(?<=[.!?])\s+(?=[¿¡A-ZÁÉÍÓÚÑ0-9])/u', $text) ?: [];

        return array_values(array_filter(array_map('trim', $parts), fn ($s) => mb_strlen($s) > 3));
    }

    /** Fecha mencionada en la frase (YYYY-MM-DD), relativa al día en que llegó el correo. */
    public function dueDate(string $sentence, CarbonImmutable $day): ?string
    {
        $s = mb_strtolower(strtr($sentence, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'Á' => 'a', 'É' => 'e', 'Í' => 'i', 'Ó' => 'o', 'Ú' => 'u', 'ñ' => 'n', 'Ñ' => 'n']));

        if (preg_match('/\bpasado manana\b/', $s)) {
            return $day->addDays(2)->toDateString();
        }
        if (preg_match('/\b(manana)\b/', $s) && ! preg_match('/\b(en|por|de) la manana\b/', $s)) {
            return $day->addDay()->toDateString();
        }
        if (preg_match('/\b(hoy|hoy mismo|durante el dia)\b/', $s)) {
            return $day->toDateString();
        }
        // 15/11, 15-11-2026, 15/11/26
        if (preg_match('/\b(\d{1,2})[\/\-](\d{1,2})(?:[\/\-](\d{2,4}))?\b/', $s, $m) && checkdate((int) $m[2], (int) $m[1], (int) ($m[3] ?? $day->year))) {
            return $this->resolve($day, (int) $m[1], (int) $m[2], $m[3] ?? null);
        }
        // 15 de noviembre
        if (preg_match('/\b(\d{1,2}) de ('.implode('|', array_keys(self::MONTHS)).')\b/', $s, $m)) {
            $month = self::MONTHS[$m[2]];
            if (checkdate($month, (int) $m[1], $day->year)) {
                return $this->resolve($day, (int) $m[1], $month, null);
            }
        }
        // el viernes, este lunes, próximo martes → el siguiente con ese nombre (nunca el mismo día)
        if (preg_match('/\b('.implode('|', array_keys(self::WEEKDAYS)).')\b/', $s, $m)) {
            $target = self::WEEKDAYS[$m[1]];
            $diff = ($target - $day->dayOfWeek + 7) % 7 ?: 7;

            return $day->addDays($diff)->toDateString();
        }
        if (preg_match('/\bfin de mes\b/', $s)) {
            return $day->endOfMonth()->toDateString();
        }

        return null;
    }

    private function resolve(CarbonImmutable $day, int $d, int $month, ?string $year): string
    {
        if ($year !== null) {
            $y = strlen($year) === 2 ? 2000 + (int) $year : (int) $year;

            return CarbonImmutable::create($y, $month, $d, 0, 0, 0, $day->timezone)->toDateString();
        }
        $date = CarbonImmutable::create($day->year, $month, $d, 0, 0, 0, $day->timezone);
        // Sin año: si ya pasó hace más de un mes, es del año siguiente (ej. "10/01" escrito en diciembre)
        if ($date->lessThan($day->subMonth())) {
            $date = $date->addYear();
        }

        return $date->toDateString();
    }
}
