<?php

namespace Ernestdefoe\Salvage\Net;

/**
 * Decides whether an IP address is one the forum may connect to.
 *
 * Works on the packed binary form from inet_pton(), so every spelling of an
 * address (compressed IPv6, mapped IPv4, upper case) lands on the same bytes
 * before it is compared. Anything that is not plainly a public unicast
 * address is refused.
 */
final class Address
{
    /** IPv4 ranges that are never public (IANA special-purpose registry). */
    private const BLOCKED_V4 = [
        '0.0.0.0/8',        // "this network"
        '10.0.0.0/8',       // private
        '100.64.0.0/10',    // CGNAT (also Alibaba's metadata service, 100.100.100.200)
        '127.0.0.0/8',      // loopback
        '169.254.0.0/16',   // link-local, cloud metadata (169.254.169.254)
        '172.16.0.0/12',    // private
        '192.0.0.0/24',     // IETF protocol assignments
        '192.0.2.0/24',     // documentation
        '192.31.196.0/24',  // AS112
        '192.52.193.0/24',  // AMT
        '192.88.99.0/24',   // 6to4 relay anycast
        '192.168.0.0/16',   // private
        '192.175.48.0/24',  // AS112
        '198.18.0.0/15',    // benchmarking
        '198.51.100.0/24',  // documentation
        '203.0.113.0/24',   // documentation
        '224.0.0.0/4',      // multicast
        '240.0.0.0/4',      // reserved, broadcast
    ];

    /**
     * IPv6 is allow-listed to global unicast (2000::/3) first, which already
     * excludes ::1, ::, IPv4-mapped and -compatible, NAT64 (64:ff9b::/96),
     * discard, unique-local (fc00::/7, incl. fd00:ec2::254), link-local,
     * site-local and multicast. These are the special blocks inside 2000::/3.
     */
    private const BLOCKED_V6 = [
        '2001::/23',      // IETF protocol assignments, incl. Teredo (2001::/32)
        '2001:db8::/32',  // documentation
        '2002::/16',      // 6to4: embeds an IPv4 address
        '3fff::/20',      // documentation
    ];

    public static function isPublic(string $ip): bool
    {
        // Canonical forms only: some libc inet_pton() accept "01.2.3.4".
        $packed = filter_var($ip, FILTER_VALIDATE_IP) === false ? false : @inet_pton($ip);

        if ($packed === false) {
            return false;
        }

        if (strlen($packed) === 4) {
            foreach (self::BLOCKED_V4 as $cidr) {
                if (self::inRange($packed, $cidr)) {
                    return false;
                }
            }

            return true;
        }

        if (! self::inRange($packed, '2000::/3')) {
            return false;
        }

        foreach (self::BLOCKED_V6 as $cidr) {
            if (self::inRange($packed, $cidr)) {
                return false;
            }
        }

        return true;
    }

    private static function inRange(string $packed, string $cidr): bool
    {
        [$base, $bits] = explode('/', $cidr);
        $net = (string) inet_pton($base);

        if (strlen($net) !== strlen($packed)) {
            return false;
        }

        $bits = (int) $bits;
        $bytes = intdiv($bits, 8);

        if (strncmp($packed, $net, $bytes) !== 0) {
            return false;
        }

        $rest = $bits % 8;

        if ($rest === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $rest)) & 0xFF;

        return (ord($packed[$bytes]) & $mask) === (ord($net[$bytes]) & $mask);
    }
}
