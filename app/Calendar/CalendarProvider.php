<?php

namespace App\Calendar;

use App\Models\CalendarAccount;
use Carbon\CarbonImmutable;

interface CalendarProvider
{
    /** @return list<RemoteCalendar> */
    public function listCalendars(CalendarAccount $account): array;

    /** @return list<EventData> Eventos (con repeticiones expandidas) que se cruzan con el rango. */
    public function fetchEvents(CalendarAccount $account, CarbonImmutable $from, CarbonImmutable $to): array;

    /** Crea el evento en la agenda remota y lo devuelve con su id remoto. */
    public function createEvent(CalendarAccount $account, EventData $event): EventData;

    /** Elimina el evento de la agenda remota (remoteId tal como lo entregó fetchEvents). */
    public function deleteEvent(CalendarAccount $account, string $remoteId): void;
}
