<?php

namespace Ernestdefoe\Salvage\Net;

/**
 * Looks a host name up in DNS. An interface so tests can answer with any
 * address they need to prove a refusal.
 */
interface Resolver
{
    /**
     * @return list<string> Every A and AAAA address of the host; empty when it has none.
     *
     * @throws Refused 'dns' when the lookup itself failed
     */
    public function resolve(string $host): array;
}
