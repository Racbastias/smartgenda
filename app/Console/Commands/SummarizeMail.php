<?php

namespace App\Console\Commands;

use App\Inbox\InboxDigest;
use App\Models\CalendarAccount;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class SummarizeMail extends Command
{
    protected $signature = 'correo:resumir';

    protected $description = 'Lee los correos nuevos de las cuentas con resumen activado y los resume';

    public function handle(InboxDigest $digest): int
    {
        CalendarAccount::where('mail_enabled', true)->with('user')->each(function (CalendarAccount $account) use ($digest) {
            $count = $digest->run($account);
            $this->line("{$account->user->name} · {$account->name}: {$count} correos nuevos".($account->mail_error ? " (error: {$account->mail_error})" : ''));
        });
        Cache::forever('smartgenda:mail-run', now()->utc()->toDateTimeString());

        return self::SUCCESS;
    }
}
