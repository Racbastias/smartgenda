<?php

namespace App\Inbox;

use Carbon\CarbonImmutable;

/** Un correo leído del servidor, antes de resumirlo. El cuerpo no se guarda en la base. */
final readonly class MailMessage
{
    public function __construct(
        public string $remoteId,
        public ?string $conversationId,
        public ?string $fromName,
        public ?string $fromEmail,
        public string $subject,
        public CarbonImmutable $receivedAt,
        public string $body,
        public bool $replied = false,          // ya se respondió desde la cuenta
        public bool $directlyAddressed = true, // la persona está en "Para" (no solo en copia)
        public bool $bulk = false,             // envío masivo (List-Unsubscribe / Precedence: bulk)
    ) {}
}
