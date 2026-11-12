<?php

namespace App\Inbox;

use App\Models\User;

interface EmailSummarizer
{
    /**
     * @param  list<MailMessage>  $messages
     * @return array<string, EmailSummary> por remoteId; un correo que no se pudo resumir no aparece
     */
    public function summarize(User $user, array $messages): array;
}
