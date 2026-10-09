<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * F-010 (J1): outbox transaccional del SDK. Primera migracion que publica el paquete
 * (`php artisan vendor:publish --tag=smartmailto-migrations`).
 *
 * Va en la misma conexion que la accion de negocio (`smartmailto.outbox.connection`): la fila se guarda
 * en la transaccion del llamador y desaparece si esa transaccion se revierte.
 */
return new class extends Migration
{
    public function getConnection(): ?string
    {
        return config('smartmailto.outbox.connection') ?: null;
    }

    public function up(): void
    {
        Schema::create(config('smartmailto.outbox.table', 'smartmailto_outbox'), function (Blueprint $table) {
            $table->id();
            // track | send | identify | link | external_report
            $table->string('kind', 20);
            // event_id (track), idempotency_key (send y su external_report), link_id (link), propia (identify)
            $table->string('key', 191);
            // Cuerpo exacto a mandar, cifrado con APP_KEY (puede llevar adjuntos chicos en base64).
            $table->longText('payload');
            // Slug de la plantilla (send / external_report): agrupa los rechazos sin descifrar.
            $table->string('template')->nullable();
            $table->timestamp('send_before')->nullable();
            // pending | sending | acked | failed | expired | superseded
            $table->string('status', 20)->default('pending');
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamp('first_attempt_at')->nullable();
            $table->timestamp('acked_at')->nullable();
            // Respuesta del servidor (id, duplicate, received_at, status de la consulta), sin datos personales.
            $table->text('ack')->nullable();
            // Motivo del cierre: estado HTTP del rechazo, `expired` o `gave_up`.
            $table->string('reason', 20)->nullable();
            // Estado HTTP + codigo de error del ultimo intento; nunca datos personales.
            $table->string('last_error')->nullable();
            $table->timestamps();

            $table->unique(['kind', 'key']);
            $table->index(['status', 'next_attempt_at']);
        });

        // Estado de las alertas agrupadas (decision de Jose 2026-10-08 11:50): una fila por tipo.
        Schema::create(config('smartmailto.outbox.alert_table', 'smartmailto_outbox_alert_state'), function (Blueprint $table) {
            $table->id();
            // unacked | rejected:{plantilla o tipo} | heartbeat
            $table->string('type', 191)->unique();
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('first_alert_at')->nullable();
            $table->timestamp('last_alert_at')->nullable();
            $table->unsignedInteger('next_step')->default(0);
            $table->unsignedInteger('count')->default(0);
            $table->timestamp('window_ends_at')->nullable();
            $table->timestamp('final_sent_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            // Ultima llave y error del grupo (solo identificadores, sin datos personales).
            $table->text('context')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('smartmailto.outbox.alert_table', 'smartmailto_outbox_alert_state'));
        Schema::dropIfExists(config('smartmailto.outbox.table', 'smartmailto_outbox'));
    }
};
