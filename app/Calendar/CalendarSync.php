<?php

namespace App\Calendar;

use App\Models\CalendarAccount;
use App\Models\Event;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

/** Trae los eventos de una agenda remota y deja la copia local igual a la remota en la ventana dada. */
class CalendarSync
{
    public const DAYS_BACK = 14;

    public const DAYS_AHEAD = 90;

    public function __construct(private ProviderRegistry $providers) {}

    public function sync(CalendarAccount $account, ?CarbonImmutable $from = null, ?CarbonImmutable $to = null): bool
    {
        $from ??= CarbonImmutable::now('UTC')->subDays(self::DAYS_BACK)->startOfDay();
        $to ??= CarbonImmutable::now('UTC')->addDays(self::DAYS_AHEAD)->endOfDay();

        try {
            $remote = $this->providers->for($account)->fetchEvents($account, $from, $to);
        } catch (Throwable $e) {
            $account->forceFill(['last_error' => $e->getMessage()])->save();
            report($e);

            return false;
        }

        DB::transaction(function () use ($account, $remote, $from, $to) {
            $seen = [];
            foreach ($remote as $event) {
                $seen[] = $event->remoteId;
                $account->events()->updateOrCreate(['remote_id' => $event->remoteId], $event->toAttributes());
            }

            // Lo que ya no está en la agenda remota se borró allá.
            $account->events()
                ->where('starts_at', '<', $to)
                ->where('ends_at', '>', $from)
                ->whereNotIn('remote_id', $seen ?: [''])
                ->delete();

            $account->forceFill(['last_synced_at' => now(), 'last_error' => null])->save();
        });

        return true;
    }

    public function create(CalendarAccount $account, EventData $event): Event
    {
        $created = $this->providers->for($account)->createEvent($account, $event);

        return $account->events()->updateOrCreate(['remote_id' => $created->remoteId], $created->toAttributes());
    }
}
