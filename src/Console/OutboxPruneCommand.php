<?php

namespace Agavesoft\Smartmailto\Console;

use Agavesoft\Smartmailto\Outbox\Outbox;
use Illuminate\Console\Command;

/**
 * F-010: retencion del outbox (acked 7 dias; failed, expired y superseded 30 dias) y purga ARCO de una
 * persona (`--contact=correo` o `--contact=user_id`): borra sus filas en cualquier estado salvo las que
 * estan en vuelo. Una fila pendiente borrada ya no llega a Smartmailto.
 */
class OutboxPruneCommand extends Command
{
    protected $signature = 'smartmailto:outbox:prune
        {--contact= : Correo o user_id de la persona (ARCO)}';

    protected $description = 'Purga el outbox de Smartmailto (retencion o ARCO de una persona)';

    public function handle(Outbox $outbox): int
    {
        $contact = $this->option('contact');

        if ($contact !== null) {
            $this->info($this->pruneContact($outbox, trim((string) $contact)).' fila(s) de la persona borradas.');

            return self::SUCCESS;
        }

        $acked = $outbox->table()->where('status', Outbox::ACKED)
            ->where('updated_at', '<', now()->subDays((int) $outbox->config('retention.acked_days', 7)))->delete();
        $closed = $outbox->table()->whereIn('status', [Outbox::FAILED, Outbox::EXPIRED, Outbox::SUPERSEDED])
            ->where('updated_at', '<', now()->subDays((int) $outbox->config('retention.failed_days', 30)))->delete();

        $this->info("{$acked} acked y {$closed} cerradas borradas.");

        return self::SUCCESS;
    }

    /** El payload va cifrado: se revisa fila por fila (el outbox es chico: lo pendiente y unos dias). */
    private function pruneContact(Outbox $outbox, string $contact): int
    {
        $needle = mb_strtolower($contact);
        $ids = [];

        $outbox->table()->where('status', '!=', Outbox::SENDING)->orderBy('id')->chunk(200, function ($rows) use ($outbox, $needle, &$ids) {
            foreach ($rows as $row) {
                $body = $outbox->decode($row);
                $identities = [$body, $body['survivor'] ?? [], $body['absorbed'] ?? [], $body['origin'] ?? []];
                foreach ($identities as $identity) {
                    if (mb_strtolower((string) ($identity['email'] ?? '')) === $needle || (string) ($identity['user_id'] ?? '') === $needle) {
                        $ids[] = $row->id;
                        break;
                    }
                }
            }
        });

        foreach (array_chunk($ids, 500) as $chunk) {
            $outbox->table()->whereIn('id', $chunk)->delete();
        }

        return count($ids);
    }
}
