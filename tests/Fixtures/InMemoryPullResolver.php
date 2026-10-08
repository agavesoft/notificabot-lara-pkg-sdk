<?php

namespace Agavesoft\Smartmailto\Tests\Fixtures;

use Agavesoft\Smartmailto\Contracts\PullResolver;
use Agavesoft\Smartmailto\Identity;
use Agavesoft\Smartmailto\Pull\PullContact;
use Agavesoft\Smartmailto\Pull\PullCursor;
use Agavesoft\Smartmailto\Pull\PullPage;
use Carbon\CarbonInterface;

/** Resolver de prueba: una "tabla" en memoria recorrida en orden (updatedAt, key), como lo haria una consulta. */
class InMemoryPullResolver implements PullResolver
{
    /** @var list<PullContact> */
    public array $rows = [];

    /** @var list<Identity> */
    public array $deleted = [];

    /** @var list<array<string, mixed>> */
    public array $calls = [];

    public function contact(Identity $identity, ?CarbonInterface $eventsSince): ?PullContact
    {
        $this->calls[] = ['op' => 'contact', 'identity' => $identity, 'events_since' => $eventsSince];

        foreach ($this->rows as $row) {
            if (($identity->userId !== null && $row->identity->userId === $identity->userId)
                || ($identity->userId === null && $row->identity->email === $identity->email)) {
                return $row;
            }
        }

        return null;
    }

    public function contacts(?CarbonInterface $updatedSince, ?PullCursor $after, int $limit): PullPage
    {
        $this->calls[] = ['op' => 'contacts', 'updated_since' => $updatedSince, 'after' => $after, 'limit' => $limit];

        $rows = $this->rows;
        usort($rows, fn (PullContact $a, PullContact $b) => [$a->updatedAt, $a->cursorKey()] <=> [$b->updatedAt, $b->cursorKey()]);

        $rows = array_values(array_filter($rows, fn (PullContact $row) => ($updatedSince === null || $row->updatedAt >= $updatedSince)
            && ($after === null || $row->updatedAt > $after->updatedAt || ($row->updatedAt == $after->updatedAt && $row->cursorKey() > $after->key))));

        return new PullPage(array_slice($rows, 0, $limit), deleted: $after === null ? $this->deleted : []);
    }
}
