<?php

namespace App\Inbox;

use App\Models\CalendarAccount;
use Carbon\CarbonImmutable;

/** Correo de una cuenta (Zimbra, Gmail): leer la bandeja de entrada, responder y mandar a la papelera. */
interface MailProvider
{
    /** @return list<MailMessage> Correos de la bandeja de entrada recibidos desde $since, del más nuevo al más antiguo. */
    public function fetchMessages(CalendarAccount $account, CarbonImmutable $since, int $limit = 50): array;

    public function trashMessage(CalendarAccount $account, string $messageId): void;

    public function reply(CalendarAccount $account, string $messageId, string $toEmail, ?string $toName, string $subject, string $body): void;
}
