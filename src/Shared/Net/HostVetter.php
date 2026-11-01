<?php

declare(strict_types=1);

namespace Fastsite\Shared\Net;

/**
 * "Is this host safe to open a connection to?" — resolves DNS ourselves and
 * rejects private, loopback and reserved addresses (IPv4 AND IPv6).
 *
 * Extracted from SafeUrlFetcher so the FTP publisher shares one implementation
 * rather than growing a second, weaker copy. The blocklist below is the part
 * worth not reinventing: PHP's own NO_PRIV_RANGE|NO_RES_RANGE flags miss CGNAT,
 * NAT64 and 6to4, each of which can wrap a link-local metadata address.
 *
 * The returned IP is meant to be PINNED on the connection (CURLOPT_RESOLVE) so
 * a second DNS lookup cannot swap a vetted address for a hostile one.
 */
final class HostVetter
{
    /**
     * Special-use ranges PHP's NO_PRIV_RANGE|NO_RES_RANGE flags do NOT
     * cover — CGNAT, protocol assignments, benchmarking, 6to4, NAT64.
     */
    private const BLOCKED_CIDRS = [
        '100.64.0.0/10',   // RFC 6598 CGNAT (K8s pod/node nets)
        '192.0.0.0/24',    // IETF protocol assignments (incl. NAT64 discovery)
        '198.18.0.0/15',   // benchmarking
        '192.88.99.0/24',  // 6to4 relay anycast
        '2002::/16',       // 6to4
        '64:ff9b::/96',    // NAT64
        '64:ff9b:1::/48',  // NAT64 local-use
    ];

    /**
     * @return string the vetted IP to pin the connection to
     *
     * @throws \InvalidArgumentException when the host does not resolve, or
     *                                   resolves only to non-public addresses
     */
    public function vet(string $host): string
    {
        return $this->vetAll($host)[0];
    }

    /**
     * EVERY public address the host resolves to, IPv4 first.
     *
     * Pinning a single address is a trap: a host with an AAAA record its
     * clients cannot actually route (no IPv6 from the container) hangs until
     * the connect timeout and reports "connection timed out", while a desktop
     * client on the same credentials connects instantly over IPv4. Handing the
     * whole list to the caller lets curl fail over the way a normal resolver
     * would, while every address in it has still been vetted.
     *
     * @return non-empty-list<string>
     *
     * @throws \InvalidArgumentException when the host does not resolve, or
     *                                   resolves only to non-public addresses
     */
    public function vetAll(string $host): array
    {
        // A bare IP literal is vetted directly; a hostname is resolved.
        $candidates = filter_var($host, FILTER_VALIDATE_IP) !== false
            ? [$host]
            : $this->resolve($host);

        $public = [];
        foreach ($candidates as $ip) {
            if ($this->isPublic($ip)) {
                $public[] = $ip;
            }
        }

        if ($public === []) {
            throw new \InvalidArgumentException('Refusing to connect to a private, loopback, or reserved address.');
        }

        // IPv4 first — it is the family that works everywhere, and trying it
        // before a possibly-unroutable AAAA is what keeps a misconfigured
        // record from costing the whole connect timeout.
        usort($public, static fn (string $a, string $b): int => (int) str_contains($a, ':') <=> (int) str_contains($b, ':'));

        return $public;
    }

    /**
     * @return list<string>
     */
    private function resolve(string $host): array
    {
        $records = @dns_get_record($host, DNS_A | DNS_AAAA) ?: [];
        $ips = [];
        foreach ($records as $r) {
            if (isset($r['ip'])) {
                $ips[] = (string) $r['ip'];
            } elseif (isset($r['ipv6'])) {
                $ips[] = (string) $r['ipv6'];
            }
        }
        if ($ips === []) {
            throw new \InvalidArgumentException('Could not resolve host: ' . $host);
        }

        return $ips;
    }

    private function isPublic(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }

        $packed = @inet_pton($ip);
        if ($packed === false) {
            return false;
        }

        // An IPv4-mapped IPv6 address (::ffff:a.b.c.d) must be judged on its
        // embedded IPv4 so a blocked v4 can't be smuggled in v6 form.
        if (\strlen($packed) === 16 && str_starts_with($packed, "\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\xff\xff")) {
            return $this->isPublic(inet_ntop(substr($packed, 12)) ?: '0.0.0.0');
        }

        foreach (self::BLOCKED_CIDRS as $cidr) {
            if ($this->inCidr($packed, $cidr)) {
                return false;
            }
        }

        return true;
    }

    private function inCidr(string $packed, string $cidr): bool
    {
        [$net, $bits] = explode('/', $cidr);
        $netPacked = @inet_pton($net);
        if ($netPacked === false || \strlen($netPacked) !== \strlen($packed)) {
            return false; // different family — can't match
        }

        $bits = (int) $bits;
        $fullBytes = intdiv($bits, 8);
        if ($fullBytes > 0 && strncmp($packed, $netPacked, $fullBytes) !== 0) {
            return false;
        }

        $remainder = $bits % 8;
        if ($remainder === 0) {
            return true;
        }
        $mask = 0xFF << (8 - $remainder) & 0xFF;

        return (\ord($packed[$fullBytes]) & $mask) === (\ord($netPacked[$fullBytes]) & $mask);
    }
}
