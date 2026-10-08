<?php

namespace Agavesoft\Smartmailto\Http\Controllers;

use Agavesoft\Smartmailto\Contracts\PullResolver;
use Agavesoft\Smartmailto\Identity;
use Agavesoft\Smartmailto\Pull\PullCatalog;
use Agavesoft\Smartmailto\Pull\PullContact;
use Agavesoft\Smartmailto\Pull\PullCursor;
use Agavesoft\Smartmailto\Pull\PullRoute;
use Agavesoft\Smartmailto\Pull\PullSignature;
use Agavesoft\Smartmailto\Pull\PullTime;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use InvalidArgumentException;
use RuntimeException;

/**
 * F-011: atiende `POST {prefix}/smartmailto/pull` (contrato de pull v1). Llega ya verificada por el
 * middleware `smartmailto.pull`.
 *
 *  - `op: contact`: un contacto por `identity` (`user_id` o `email`); `contacts: []` si no existe.
 *  - `op: contacts`: pagina desde `updated_since` (null = carga inicial) y `cursor`, con `limit` (default
 *    200, tope `pull.max_limit`).
 *
 * Solo la respuesta 200 va firmada (`X-Smartmailto-Timestamp` y `X-Smartmailto-Signature` sobre los bytes
 * exactos del cuerpo). Los errores de la peticion son 4xx (definitivos para Smartmailto); un resolver que
 * falla o un catalogo que no se puede leer son 5xx (Smartmailto reintenta).
 */
class PullController
{
    public const VERSION = 1;

    public const DEFAULT_LIMIT = 200;

    public function __invoke(Request $request, Container $app): Response|JsonResponse
    {
        // La ruta pudo quedar registrada (route:cache) aunque despues se apagara el pull o el resolver.
        $resolver = config('smartmailto.enabled', true) && config('smartmailto.pull.enabled', false) ? PullRoute::resolver($app) : null;
        if ($resolver === null) {
            return new JsonResponse(['error' => 'not_found'], 404);
        }

        $data = json_decode($request->getContent(), true);
        if (! is_array($data) || ($data['version'] ?? null) !== self::VERSION) {
            return $this->invalid('unsupported_version');
        }

        $include = is_array($data['include'] ?? null) ? $data['include'] : ['attributes', 'events'];
        $attributeKeys = null;
        if (in_array('attributes', $include, true)) {
            $attributeKeys = $app->make(PullCatalog::class)->contactKeys();
            if ($attributeKeys === null) {
                return new JsonResponse(['error' => 'catalog_unavailable'], 503);
            }
        }

        $secret = (string) config('smartmailto.pull.secret');
        $horizon = $this->historyHorizon();

        $body = match ($data['op'] ?? null) {
            'contact' => $this->contact($resolver, $data, $horizon),
            'contacts' => $this->contacts($resolver, $data, $secret),
            default => 'unsupported_op',
        };
        if (is_string($body)) {
            return $this->invalid($body);
        }

        [$contacts, $nextCursor, $deleted] = $body;

        return $this->signed($request, $secret, $contacts, $nextCursor, $deleted, $attributeKeys, in_array('events', $include, true), $horizon);
    }

    /** @return array{0: list<PullContact>, 1: PullCursor|null, 2: list<Identity>}|string */
    private function contact(PullResolver $resolver, array $data, ?CarbonImmutable $horizon): array|string
    {
        $identity = $this->identity($data['identity'] ?? null);
        if ($identity === null) {
            return 'invalid_identity';
        }

        $eventsSince = PullTime::parse($data['events_since'] ?? null);
        if ($horizon !== null && ($eventsSince === null || $eventsSince < $horizon)) {
            $eventsSince = $horizon;
        }

        $contact = $resolver->contact($identity, $eventsSince);

        return [$contact === null ? [] : [$contact], null, []];
    }

    /** @return array{0: list<PullContact>, 1: PullCursor|null, 2: list<Identity>}|string */
    private function contacts(PullResolver $resolver, array $data, string $secret): array|string
    {
        $updatedSince = null;
        if (($data['updated_since'] ?? null) !== null && ($updatedSince = PullTime::parse($data['updated_since'])) === null) {
            return 'invalid_updated_since';
        }

        $after = null;
        if (($data['cursor'] ?? null) !== null && (! is_string($data['cursor']) || ($after = PullCursor::decode($data['cursor'], $secret)) === null)) {
            return 'invalid_cursor';
        }

        $limit = $data['limit'] ?? self::DEFAULT_LIMIT;
        if (! is_int($limit) || $limit < 1) {
            return 'invalid_limit';
        }
        $limit = min($limit, max(1, (int) config('smartmailto.pull.max_limit', 500)));

        $page = $resolver->contacts($updatedSince, $after, $limit);
        $contacts = $page->contacts;
        $hasMore = $page->hasMore ?? count($contacts) >= $limit;
        if (count($contacts) > $limit) {
            $contacts = array_slice($contacts, 0, $limit);
            $hasMore = true;
        }

        $this->assertOrdered($contacts, $after);

        $next = $hasMore && $contacts !== [] ? PullCursor::after($contacts[array_key_last($contacts)]) : null;

        return [$contacts, $next, $page->deleted];
    }

    /**
     * Un `updatedAt` que retrocede haria que el cursor se salte contactos: se falla en voz alta (500) en
     * vez de perder datos en silencio. El desempate por `key` lo compara el resolver en su base.
     *
     * @param  list<PullContact>  $contacts
     */
    private function assertOrdered(array $contacts, ?PullCursor $after): void
    {
        $previous = $after?->updatedAt;
        foreach ($contacts as $contact) {
            $current = CarbonImmutable::instance($contact->updatedAt);
            if ($previous !== null && $current < $previous) {
                throw new RuntimeException('Smartmailto PullResolver::contacts() must return contacts ordered by (updatedAt, key) and after the cursor.');
            }
            $previous = $current;
        }
    }

    /**
     * @param  list<PullContact>  $contacts
     * @param  list<Identity>  $deleted
     * @param  list<string>|null  $attributeKeys
     */
    private function signed(Request $request, string $secret, array $contacts, ?PullCursor $nextCursor, array $deleted, ?array $attributeKeys, bool $includeEvents, ?CarbonImmutable $horizon): Response
    {
        $encoded = array_map(fn (PullContact $contact) => $this->json($contact->toPayload($attributeKeys, $includeEvents, $horizon)), $contacts);

        // La respuesta debe caber en el limite del servidor (5 MB): se recorta la pagina y el cursor sigue
        // desde el ultimo contacto que si cupo.
        $budget = max(1024, (int) config('smartmailto.pull.max_response_bytes', 5_000_000)) - 1024;
        $deletedJson = $this->json(array_map(fn (Identity $identity) => $identity->toArray(), $deleted));
        $used = strlen($deletedJson);
        $kept = 0;
        foreach ($encoded as $json) {
            if ($used + strlen($json) + 1 > $budget) {
                break;
            }
            $used += strlen($json) + 1;
            $kept++;
        }

        if ($kept < count($encoded)) {
            if ($kept === 0) {
                throw new RuntimeException('A single Smartmailto pull contact exceeds smartmailto.pull.max_response_bytes; send fewer events per contact (history_months).');
            }
            $encoded = array_slice($encoded, 0, $kept);
            $nextCursor = PullCursor::after($contacts[$kept - 1]);
        }

        $body = '{"version":'.self::VERSION
            .',"contacts":['.implode(',', $encoded).']'
            .($deleted !== [] ? ',"deleted":'.$deletedJson : '')
            .',"next_cursor":'.$this->json($nextCursor?->encode($secret)).'}';

        $timestamp = now()->getTimestamp();

        return new Response($body, 200, [
            'Content-Type' => 'application/json',
            'X-Smartmailto-Timestamp' => (string) $timestamp,
            'X-Smartmailto-Signature' => PullSignature::response($secret, $timestamp, (string) $request->header('X-Smartmailto-Request'), $body),
        ]);
    }

    private function identity(mixed $identity): ?Identity
    {
        if (! is_array($identity)) {
            return null;
        }

        try {
            if (isset($identity['user_id']) && (is_string($identity['user_id']) || is_int($identity['user_id']))) {
                return Identity::user($identity['user_id'], is_string($identity['email'] ?? null) ? $identity['email'] : null);
            }

            return is_string($identity['email'] ?? null) ? Identity::guest($identity['email']) : null;
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    /** Inicio de la historia configurada (`history_months`); null = toda. */
    private function historyHorizon(): ?CarbonImmutable
    {
        $months = config('smartmailto.pull.history_months');

        return $months === null || $months === '' ? null : CarbonImmutable::now()->subMonths(max(0, (int) $months));
    }

    private function json(mixed $value): string
    {
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    private function invalid(string $error): JsonResponse
    {
        return new JsonResponse(['error' => $error], 422);
    }
}
