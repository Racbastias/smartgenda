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
    ) {}
}
