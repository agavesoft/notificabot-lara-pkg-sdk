<?php

namespace Agavesoft\Smartmailto\Console;

use Agavesoft\Smartmailto\Outbox\OutboxWorker;
use Illuminate\Console\Command;

/**
 * F-010 (J1): entrega el outbox. Como daemon (supervisor) o desde el scheduler cada minuto con `--once`
 * o `--max-time=55`. Varios workers son seguros: cada fila se toma con un UPDATE condicionado.
 */
class OutboxWorkCommand extends Command
{
    protected $signature = 'smartmailto:outbox:work
        {--once : Una sola pasada}
        {--sleep=5 : Segundos entre pasadas}
        {--max-time=0 : Segundos que corre antes de salir (0 = sin limite)}';

    protected $description = 'Entrega las filas del outbox de Smartmailto (acuse, reintentos, emergencia y alertas)';

    public function handle(OutboxWorker $worker): int
    {
        if (! config('smartmailto.enabled', true) || ! config('smartmailto.outbox.enabled')) {
            $this->warn('El outbox de Smartmailto esta apagado (SMARTMAILTO_OUTBOX_ENABLED).');

            return self::SUCCESS;
        }

        $started = time();
        $maxTime = (int) $this->option('max-time');

        do {
            $stats = $worker->runOnce();
            if ($stats['delivered'] > 0 || $stats['closed'] > 0) {
                $this->line(sprintf('entregadas %d · acuse %d · reintento %d · cerradas %d', $stats['delivered'], $stats['acked'], $stats['retried'], $stats['closed']));
            }

            if ($this->option('once') || ($maxTime > 0 && time() - $started + (int) $this->option('sleep') >= $maxTime)) {
                break;
            }

            sleep(max(1, (int) $this->option('sleep')));
        } while (true);

        return self::SUCCESS;
    }
}
