<?php

namespace App\Calendar;

/** Un calendario disponible en la cuenta remota, para que el usuario elija cuál conectar. */
final class RemoteCalendar
{
    public function __construct(
        public string $ref,
        public string $name,
    ) {}
}
