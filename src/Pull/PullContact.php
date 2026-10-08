<?php

namespace Agavesoft\Smartmailto\Pull;

use Agavesoft\Smartmailto\Identity;
use DateTimeInterface;
use InvalidArgumentException;

/**
 * F-011: un contacto como lo devuelve tu PullResolver.
 *
 *  - `updatedAt`: hora del ultimo cambio en tu app. Smartmailto la usa para decidir que dato gana contra
 *    el push (gana el mas reciente) y el SDK la usa para el cursor.
 *  - `key`: desempate del orden `(updatedAt, key)`, normalmente el id de la fila que recorres. Sin el se
 *    usa el user_id o el correo de la identidad.
 *  - `attributes`: solo las llaves del catalogo de contacto (F-009) llegan a Smartmailto; el SDK descarta
 *    las demas antes de responder.
 *  - `unsubscribed`: la baja de tu app; Smartmailto la respeta y el pull nunca la quita.
 */
final class PullContact
{
    /** @var list<PullEvent> */
    public readonly array $events;

    /**
     * @param  array<string, mixed>  $attributes
     * @param  list<PullEvent>  $events
     */
    public function __construct(
        public readonly Identity $identity,
        public readonly DateTimeInterface $updatedAt,
        public readonly ?DateTimeInterface $contactSince = null,
        public readonly array $attributes = [],
        array $events = [],
        public readonly bool $unsubscribed = false,
        public readonly int|string|null $key = null,
    ) {
        foreach ($events as $event) {
            if (! $event instanceof PullEvent) {
                throw new InvalidArgumentException('PullContact events must be PullEvent instances.');
            }
        }

        $this->events = array_values($events);
    }

    public function cursorKey(): int|string
    {
        return $this->key ?? $this->identity->userId ?? (string) $this->identity->email;
    }

    /**
     * @internal cuerpo del contrato v1, ya filtrado
     *
     * @param  list<string>|null  $attributeKeys  llaves permitidas; null = no incluir atributos
     * @param  DateTimeInterface|null  $eventsSince  descarta eventos anteriores (historia configurada)
     * @return array<string, mixed>
     */
    public function toPayload(?array $attributeKeys, bool $includeEvents, ?DateTimeInterface $eventsSince): array
    {
        $payload = [
            ...$this->identity->toArray(),
            'contact_since' => $this->contactSince ? PullTime::format($this->contactSince) : null,
            'updated_at' => PullTime::format($this->updatedAt),
            'unsubscribed' => $this->unsubscribed,
        ];

        if ($attributeKeys !== null) {
            $payload['attributes'] = (object) array_intersect_key($this->attributes, array_flip($attributeKeys));
        }

        if ($includeEvents) {
            $events = $eventsSince === null
                ? $this->events
                : array_filter($this->events, fn (PullEvent $event) => $event->occurredAt >= $eventsSince);

            $payload['events'] = array_values(array_map(fn (PullEvent $event) => $event->toArray(), $events));
        }

        return array_filter($payload, fn ($value) => $value !== null);
    }
}
