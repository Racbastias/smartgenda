<?php

namespace App\Calendar;

use Carbon\CarbonImmutable;

/** Un evento tal como lo entrega (o lo recibe) una agenda externa. Fechas siempre en UTC. */
final class EventData
{
    public function __construct(
        public string $remoteId,
        public string $title,
        public CarbonImmutable $startsAt,
        public CarbonImmutable $endsAt,
        public bool $allDay = false,
        public ?string $description = null,
        public ?string $location = null,
        public bool $busy = true,
        /** @var list<string> correos de los invitados, en minúsculas */
        public array $attendees = [],
        public ?string $organizer = null,
        public ?CarbonImmutable $createdAt = null,
    ) {}

    /** @return array<string, mixed> */
    public function toAttributes(): array
    {
        return [
            'title' => $this->title,
            'description' => $this->description,
            'location' => $this->location,
            'starts_at' => $this->startsAt->utc(),
            'ends_at' => $this->endsAt->utc(),
            'all_day' => $this->allDay,
            'busy' => $this->busy,
            'attendees' => $this->attendees ?: null,
            'organizer' => $this->organizer ? mb_strtolower($this->organizer) : null,
            'remote_created_at' => $this->createdAt?->utc(),
        ];
    }
}
