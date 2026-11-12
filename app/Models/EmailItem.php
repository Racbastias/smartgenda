<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['remote_id', 'conversation_id', 'from_name', 'from_email', 'subject', 'received_at', 'summary',
    'priority', 'needs_reply', 'actions', 'due_on', 'status'])]
class EmailItem extends Model
{
    public const PENDING = 'pending';

    public const DONE = 'done';

    public const DISMISSED = 'dismissed';

    protected function casts(): array
    {
        return [
            'received_at' => 'datetime',
            'due_on' => 'date',
            'needs_reply' => 'boolean',
            'actions' => 'array',
        ];
    }

    /** @return BelongsTo<CalendarAccount, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(CalendarAccount::class, 'calendar_account_id');
    }

    /** Correos que piden algo: responder o alguna acción. */
    public function scopeActionable(Builder $query): void
    {
        $query->where(fn (Builder $q) => $q->where('needs_reply', true)->orWhereJsonLength('actions', '>', 0));
    }

    /** Título para la lista: la primera acción, o el asunto. */
    public function headline(): string
    {
        return $this->actions[0]['text'] ?? ($this->subject ?: '(sin asunto)');
    }

    /** Enlace para abrir el correo en el webmail (requiere tener sesión abierta en el webmail). */
    public function webUrl(): ?string
    {
        $account = $this->account;
        if ($account?->provider === CalendarAccount::GOOGLE) {
            return 'https://mail.google.com/mail/u/'.rawurlencode((string) ($account->username ?: '0')).'/#all/'.rawurlencode($this->remote_id);
        }
        if ($account?->provider !== CalendarAccount::ZIMBRA || ! $account->server_url) {
            return null;
        }

        return rtrim($account->server_url, '/').'/h/printmessage?id='.rawurlencode($this->remote_id);
    }

    public function sender(): string
    {
        return $this->from_name ?: ($this->from_email ?: 'Desconocido');
    }
}
