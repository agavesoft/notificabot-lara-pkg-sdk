<?php

namespace Agavesoft\Smartmailto\Console;

use Agavesoft\Smartmailto\Outbox\Outbox;
use Illuminate\Console\Command;

/**
 * F-010 (regla 2): reprocesa filas `failed` (rendidas a las 72 h o rechazadas tras corregir el dato o la
 * plantilla). Un `send` con su `send_before` ya vencido se cerrara `expired` (Smartmailto responde 410).
 */
class OutboxRetryCommand extends Command
{
    protected $signature = 'smartmailto:outbox:retry
        {id?* : Ids de filas}
        {--key= : Llave (event_id, idempotency_key, link_id)}
        {--failed : Todas las filas failed}';

    protected $description = 'Reprocesa filas failed del outbox de Smartmailto';

    public function handle(Outbox $outbox): int
    {
        $ids = (array) $this->argument('id');
        $key = $this->option('key');
        if ($ids === [] && $key === null && ! $this->option('failed')) {
            $this->error('Indica ids, --key o --failed.');

            return self::FAILURE;
        }

        $retried = $outbox->table()->where('status', Outbox::FAILED)
            ->when($ids !== [], fn ($query) => $query->whereIn('id', $ids))
            ->when($key !== null, fn ($query) => $query->where('key', $key))
            ->update([
                'status' => Outbox::PENDING,
                'attempts' => 0,
                'first_attempt_at' => null,
                'next_attempt_at' => now(),
                'reason' => null,
                'updated_at' => now(),
            ]);

        $this->info("{$retried} fila(s) de vuelta a pending.");

        return self::SUCCESS;
    }
}
