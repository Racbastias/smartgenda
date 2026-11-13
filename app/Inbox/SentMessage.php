<?php

namespace App\Inbox;

use Carbon\CarbonImmutable;

/** Un correo enviado desde la cuenta (solo lo necesario para saber qué se respondió). */
final readonly class SentMessage
{
    /** @param  list<string>  $recipients  correos en Para y CC, en minúsculas */
    public function __construct(
        public ?string $conversationId,
        public string $subject,
        public array $recipients,
        public CarbonImmutable $sentAt,
        /** Comienzo del texto (sin la cita del correo anterior, cuando el proveedor lo permite) */
        public string $snippet = '',
        public bool $hasAttachment = false,
    ) {}

    /**
     * ¿Con este envío quedas a la espera de que te respondan? Enviaste una cotización o un adjunto,
     * hiciste una pregunta o dejaste algo por confirmar.
     */
    public function awaitsAnswer(): bool
    {
        if ($this->hasAttachment) {
            return true;
        }
        $text = mb_strtolower($this->snippet);

        return str_contains($text, '?') || (bool) preg_match('/cotizaci|presupuesto|propuesta|adjunt|quedo atent|quedamos atent|'
            .'(quedo|quedamos) a la espera|a la espera de|espero (tu|su|vuestr)|me (confirmas|confirma|cuentas|cuenta|avisas|avisa)|'
            .'(nos|me) (indicas|indica)|favor confirmar|por confirmar|confirmar(nos|me)?\b/u', $text);
    }
}
