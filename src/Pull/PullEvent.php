<?php

namespace Agavesoft\Smartmailto\Pull;

use DateTimeInterface;
use InvalidArgumentException;

/**
 * F-011: un evento historico de un contacto. Smartmailto lo guarda con origen `pull`: cuenta para
 * condiciones y filtros, pero nunca dispara workflows ni correos. `eventId` debe ser el mismo que mandas
 * por push en track() (idempotencia de punta a punta).
 */
final class PullEvent
{
    /**
     * @param  array<string, mixed>  $properties
     * @param  array{0: string, 1: string|int}|array{type: string, id: string|int}|null  $object
     */
    public function __construct(
        public readonly string $eventId,
        public readonly string $event,
        public readonly DateTimeInterface $occurredAt,
        public readonly array $properties = [],
        public readonly ?array $object = null,
    ) {
        if (trim($eventId) === '' || trim($event) === '') {
            throw new InvalidArgumentException('PullEvent requires an eventId derived from the business fact and an event name.');
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return array_filter([
            'event_id' => $this->eventId,
            'event' => $this->event,
            'occurred_at' => PullTime::format($this->occurredAt),
            'properties' => (object) $this->properties,
            'object' => $this->normalizedObject(),
        ], fn ($value) => $value !== null);
    }

    /** @return array{type: string, id: string}|null */
    private function normalizedObject(): ?array
    {
        if ($this->object === null) {
            return null;
        }

        $type = $this->object['type'] ?? $this->object[0] ?? null;
        $id = $this->object['id'] ?? $this->object[1] ?? null;

        if (! is_string($type) || $id === null || $id === '') {
            throw new InvalidArgumentException('PullEvent object must be [type, id], e.g. ["order", 100].');
        }

        return ['type' => $type, 'id' => (string) $id];
    }
}
