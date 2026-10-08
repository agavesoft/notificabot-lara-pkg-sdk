<?php

namespace Agavesoft\Smartmailto\Console;

use Agavesoft\Smartmailto\Outbox\Outbox;
use Illuminate\Console\Command;
use Throwable;

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
            [$deleted, $inFlight, $unreadable] = $this->pruneContact($outbox, trim((string) $contact));
            $this->info("{$deleted} fila(s) de la persona borradas.");
            if ($inFlight > 0) {
                $this->warn("{$inFlight} fila(s) en vuelo (sending): vuelve a correr el comando en unos minutos.");
            }
            if ($unreadable > 0) {
                $this->warn("{$unreadable} fila(s) no se pudieron descifrar (APP_KEY rotada): revisalas a mano.");
            }

            return $inFlight > 0 || $unreadable > 0 ? self::FAILURE : self::SUCCESS;
        }

        $acked = $outbox->table()->where('status', Outbox::ACKED)
            ->where('updated_at', '<', now()->subDays((int) $outbox->config('retention.acked_days', 7)))->delete();
        $closed = $outbox->table()->whereIn('status', [Outbox::FAILED, Outbox::EXPIRED, Outbox::SUPERSEDED])
            ->where('updated_at', '<', now()->subDays((int) $outbox->config('retention.failed_days', 30)))->delete();

        $this->info("{$acked} acked y {$closed} cerradas borradas.");

        return self::SUCCESS;
    }

    /**
     * El payload va cifrado: se revisa fila por fila (el outbox es chico: lo pendiente y unos dias). Busca
     * en la identidad, survivor/absorbed (link), origin y los destinatarios adicionales (to, cc, bcc).
     *
     * @return array{0: int, 1: int, 2: int} borradas, en vuelo, ilegibles
     */
    private function pruneContact(Outbox $outbox, string $contact): array
    {
        $email = mb_strtolower($contact);
        $ids = [];
        $inFlight = 0;
        $unreadable = 0;

        $outbox->table()->orderBy('id')->chunk(200, function ($rows) use ($outbox, $contact, $email, &$ids, &$inFlight, &$unreadable) {
            foreach ($rows as $row) {
                try {
                    $body = $outbox->decode($row);
                } catch (Throwable) {
                    $unreadable++;

                    continue;
                }

                $identities = [$body, $body['survivor'] ?? [], $body['absorbed'] ?? [], $body['origin'] ?? []];
                $extra = array_map(fn ($address) => mb_strtolower((string) $address), [...($body['to'] ?? []), ...($body['cc'] ?? []), ...($body['bcc'] ?? [])]);
                $matches = in_array($email, $extra, true);
                foreach ($identities as $identity) {
                    $matches = $matches || mb_strtolower((string) ($identity['email'] ?? '')) === $email || (string) ($identity['user_id'] ?? '') === $contact;
                }

                if ($matches && $row->status === Outbox::SENDING) {
                    $inFlight++;
                } elseif ($matches) {
                    $ids[] = $row->id;
                }
            }
        });

        foreach (array_chunk($ids, 500) as $chunk) {
            // Solo si no la tomo un worker mientras se revisaba.
            $outbox->table()->whereIn('id', $chunk)->where('status', '!=', Outbox::SENDING)->delete();
        }

        return [count($ids), $inFlight, $unreadable];
    }
}
