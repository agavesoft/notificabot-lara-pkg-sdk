<?php

namespace Agavesoft\Smartmailto;

use DateTimeInterface;

/**
 * Lote en construccion: `Smartmailto::batch(backfill: true)->track(...)->track(...)->dispatch()`.
 */
class PendingBatch
{
    /** @var list<array<string, mixed>> */
    private array $items = [];

    public function __construct(
        private readonly Smartmailto $smartmailto,
        private readonly bool $backfill,
    ) {}

    /** @param array<string, mixed> $attributes */
    public function identify(Identity $identity, array $attributes = []): self
    {
        $this->items[] = ['type' => 'identify', ...$identity->toArray(), 'attributes' => $attributes];

        return $this;
    }

    /**
     * @param  array<string, mixed>  $properties
     * @param  array<string, string>  $secrets
     */
    public function track(
        string $event,
        Identity $identity,
        array $properties = [],
        string $eventId = '',
        array $secrets = [],
        ?array $object = null,
        ?DateTimeInterface $occurredAt = null,
    ): self {
        $this->items[] = ['type' => 'track', ...$this->smartmailto->trackBody($event, $identity, $properties, $eventId, $secrets, $object, $occurredAt)];

        return $this;
    }

    public function count(): int
    {
        return count($this->items);
    }

    /** @return list<array<string, mixed>> */
    public function items(): array
    {
        return $this->items;
    }

    public function dispatch(): void
    {
        if ($this->items !== []) {
            $this->smartmailto->deliverBatch($this->items, $this->backfill);
        }
    }
}
