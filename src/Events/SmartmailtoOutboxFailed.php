<?php

namespace Agavesoft\Smartmailto\Events;

/**
 * F-010 (RV3, regla 17): una fila del outbox se cerro sin que Smartmailto la entregue.
 *
 * Para `kind = send` es el gancho del envio de emergencia de tu app: manda ese correo por tu canal
 * directo y reportalo con Smartmailto::reportExternalSend($key). Solo se dispara cuando la emergencia
 * procede: rechazo definitivo (`reason` = estado HTTP, p. ej. "422") o `send_before` vencido sin acuse
 * (`reason` = "expired"), y despues de que el SDK consulto a Smartmailto (si respondio) que el envio no
 * salio ni esta en cola. Para track/identify/link/external_report (`reason` "422" o "gave_up" a las 72 h)
 * no hay correo que mandar: soporte lo revisa con el runbook.
 *
 * Sin datos personales: la llave, el motivo y la plantilla. Distinto de SmartmailtoDeliveryFailed
 * (entrega por cola, sin outbox).
 */
class SmartmailtoOutboxFailed
{
    public function __construct(
        public readonly int $outboxId,
        public readonly string $kind,
        public readonly string $key,
        public readonly string $reason,
        public readonly ?string $template = null,
        public readonly ?int $status = null,
        public readonly ?string $error = null,
    ) {}

    /** El correo debe salir por el canal de emergencia de tu app. */
    public function needsEmergencySend(): bool
    {
        return $this->kind === 'send';
    }
}
