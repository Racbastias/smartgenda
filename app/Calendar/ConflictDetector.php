<?php

namespace App\Calendar;

use App\Models\Event;
use Illuminate\Support\Collection;

/** Marca los eventos "ocupado" que se cruzan con otro evento de una agenda distinta. */
class ConflictDetector
{
    /**
     * @param  Collection<int, Event>  $events
     * @return array<int, list<int>> id del evento => ids con los que choca
     */
    public function detect(Collection $events): array
    {
        $timed = $events->filter(fn (Event $e) => $e->busy && ! $e->all_day)
            ->sortBy(fn (Event $e) => $e->starts_at->getTimestamp())
            ->values();

        $conflicts = [];
        $active = [];
        foreach ($timed as $event) {
            $active = array_filter($active, fn (Event $a) => $a->ends_at->gt($event->starts_at));
            foreach ($active as $other) {
                if ($other->calendar_account_id !== $event->calendar_account_id) {
                    $conflicts[$event->id][] = $other->id;
                    $conflicts[$other->id][] = $event->id;
                }
            }
            $active[] = $event;
        }

        return $conflicts;
    }
}
