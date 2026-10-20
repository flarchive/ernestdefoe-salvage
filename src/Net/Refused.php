<?php

namespace Ernestdefoe\Salvage\Net;

use RuntimeException;

/**
 * A URL the forum will not fetch, or a fetch that failed. The reason is a
 * short machine code (stored on the link and translated in the admin page),
 * never a message meant for people.
 */
final class Refused extends RuntimeException
{
    /** Codes that say the URL must never be fetched, as opposed to a failed attempt. */
    public const POLICY = ['scheme', 'credentials', 'port', 'host', 'private_address'];

    public function __construct(public readonly string $reason)
    {
        parent::__construct($reason);
    }

    public function isPolicy(): bool
    {
        return in_array($this->reason, self::POLICY, true);
    }
}
