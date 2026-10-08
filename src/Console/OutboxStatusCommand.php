<?php

namespace Agavesoft\Smartmailto\Console;

use Agavesoft\Smartmailto\Outbox\Outbox;
use Illuminate\Console\Command;

/** F-010: conteos del outbox por estado y tipo, la fila pendiente mas vieja y las alertas abiertas. */
class OutboxStatusCommand extends Command
{
    protected $signature = 'smartmailto:outbox:status';

    protected $description = 'Estado del outbox de Smartmailto';

    public function handle(Outbox $outbox): int
    {
        $rows = $outbox->table()->selectRaw('status, kind, count(*) as total')->groupBy('status', 'kind')->orderBy('status')->orderBy('kind')->get();
        $this->table(['Estado', 'Tipo', 'Filas'], $rows->map(fn ($row) => [$row->status, $row->kind, $row->total])->all());

        $oldest = $outbox->table()->whereIn('status', [Outbox::PENDING, Outbox::SENDING])->orderBy('created_at')->first();
        if ($oldest !== null) {
            $this->line("Pendiente mas vieja: {$oldest->kind} {$oldest->key} desde {$oldest->created_at} (intentos {$oldest->attempts}, ultimo error: ".($oldest->last_error ?? '-').')');
        }

        $alerts = $outbox->alertTable()->where('type', '!=', 'heartbeat')->where(fn ($query) => $query->whereNotNull('opened_at')->orWhere('count', '>', 0))->get();
        foreach ($alerts as $alert) {
            $this->line("Alerta {$alert->type}: desde {$alert->opened_at}, conteo {$alert->count}, ultimo aviso ".($alert->last_alert_at ?? '-'));
        }

        $heartbeat = $outbox->alertTable()->where('type', 'heartbeat')->value('last_alert_at');
        $this->line('Ultimo aviso de vida a Smartmailto: '.($heartbeat ?? 'nunca'));

        return self::SUCCESS;
    }
}
