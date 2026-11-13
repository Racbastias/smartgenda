<?php

namespace App\Console\Commands;

use App\Calendar\CalendarSync;
use App\Inbox\MeetingTracker;
use App\Models\CalendarAccount;
use Illuminate\Console\Command;

class SyncCalendars extends Command
{
    protected $signature = 'agenda:sync {--account= : Sincronizar solo esta agenda (id)}';

    protected $description = 'Sincroniza las agendas conectadas de todos los usuarios';

    public function handle(CalendarSync $sync, MeetingTracker $meetings): int
    {
        $accounts = CalendarAccount::query()
            ->whereNotNull('secret')->whereNotNull('calendar_ref')
            ->when($this->option('account'), fn ($q, $id) => $q->whereKey($id))
            ->get();

        foreach ($accounts as $account) {
            $ok = $sync->sync($account);
            $this->line(sprintf('%s %s (%s)', $ok ? '✓' : '✗', $account->name, $account->user->email)
                .($ok ? '' : ': '.$account->fresh()->last_error));
        }

        // Una reunión recién agendada puede cerrar el pendiente del correo que la originó
        foreach ($accounts->pluck('user')->unique('id') as $user) {
            try {
                $meetings->reconcile($user);
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return self::SUCCESS;
    }
}
