<?php

namespace Agavesoft\Smartmailto\Pull;

use Agavesoft\Smartmailto\Identity;
use InvalidArgumentException;

/**
 * F-011: una pagina de contactos del PullResolver, en orden `(updatedAt, key)`.
 *
 *  - El SDK arma el siguiente cursor desde el ultimo contacto: el resolver nunca lo codifica a mano.
 *  - `hasMore`: null = hay mas si la pagina vino llena (a lo mas una peticion de mas, vacia).
 *  - `deleted`: personas borradas en tu app; Smartmailto las saca de sus workflows y las deja inactivas
 *    (no las borra; el borrado ARCO va por Smartmailto::forget()).
 */
final class PullPage
{
    /** @var list<PullContact> */
    public readonly array $contacts;

    /** @var list<Identity> */
    public readonly array $deleted;

    /**
     * @param  list<PullContact>  $contacts
     * @param  list<Identity>  $deleted
     */
    public function __construct(array $contacts, public readonly ?bool $hasMore = null, array $deleted = [])
    {
        foreach ($contacts as $contact) {
            if (! $contact instanceof PullContact) {
                throw new InvalidArgumentException('PullPage contacts must be PullContact instances.');
            }
        }
        foreach ($deleted as $identity) {
            if (! $identity instanceof Identity) {
                throw new InvalidArgumentException('PullPage deleted must be Identity instances.');
            }
        }

        $this->contacts = array_values($contacts);
        $this->deleted = array_values($deleted);
    }

    public static function empty(): self
    {
        return new self([], false);
    }
}
