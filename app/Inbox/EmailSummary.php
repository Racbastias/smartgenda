<?php

namespace App\Inbox;

/** Lo que la IA devuelve por cada correo. */
final readonly class EmailSummary
{
    /** @param list<array{text: string, due: ?string}> $actions  due = YYYY-MM-DD o null */
    public function __construct(
        public string $summary,
        public string $priority,
        public bool $needsReply,
        public array $actions,
        public string $category = 'accion', // accion | leer | copia | ruido
    ) {}

    public const CATEGORIES = ['accion', 'leer', 'copia', 'ruido'];
}
