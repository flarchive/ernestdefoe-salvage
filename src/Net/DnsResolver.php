<?php

namespace Ernestdefoe\Salvage\Net;

final class DnsResolver implements Resolver
{
    /**
     * dns_get_record asks DNS directly: no hosts file, no numeric-host
     * expansion (gethostbyname turns "2130706433" into 127.0.0.1).
     */
    public function resolve(string $host): array
    {
        $records = @dns_get_record($host, DNS_A | DNS_AAAA);

        if ($records === false) {
            throw new Refused('dns');
        }

        $ips = [];

        foreach ($records as $record) {
            $ip = $record['ip'] ?? $record['ipv6'] ?? null;

            if (is_string($ip)) {
                $ips[] = $ip;
            }
        }

        return array_values(array_unique($ips));
    }
}
