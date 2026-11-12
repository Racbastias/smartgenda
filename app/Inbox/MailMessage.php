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
    ) {}
}
