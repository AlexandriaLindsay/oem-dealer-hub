<?php

namespace App\Integrations;

use Closure;

/**
 * Whether OEM Dealer Hub may call a webhook address. Only public HTTPS
 * addresses: never our own network (localhost, 10.x, 192.168.x, the cloud
 * metadata address...), or a webhook could be used to reach things inside
 * our hosting that the internet can't. Checked when the address is saved and
 * again before every call, since what a name points at can change.
 *
 * Local development can allow plain HTTP and private addresses
 * (DEALERDOCK_WEBHOOKS_ALLOW_PRIVATE), to test against a server on the same machine.
 */
class UrlGuard
{
    /** @param  (Closure(string): list<string>)|null  $resolve  host -> its IP addresses (tests swap this) */
    public function __construct(private ?Closure $resolve = null) {}

    /** Null when the address is fine; otherwise why not, in words for the person setting it up. */
    public function problem(string $url): ?string
    {
        $parts = parse_url($url);
        $scheme = strtolower($parts['scheme'] ?? '');
        $host = $parts['host'] ?? null;
        $allowPrivate = (bool) config('dealerdock.webhooks.allow_private');

        if (! $host || ! in_array($scheme, ['https', 'http'], true)) {
            return 'Enter a full web address, starting with https://.';
        }
        if ($scheme !== 'https' && ! $allowPrivate) {
            return 'The address must start with https://, so what we send is encrypted on the way.';
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            return 'Leave the user name and password out of the address. Check our signature instead.';
        }
        if ($allowPrivate) {
            return null;
        }

        $host = trim($host, '[]');
        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : $this->resolve($host);
        if ($ips === []) {
            return 'That address can\'t be found. Check the spelling.';
        }
        foreach ($ips as $ip) {
            if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return 'That address points inside a private network. Use one that\'s reachable from the internet.';
            }
        }

        return null;
    }

    /** @return list<string> */
    private function resolve(string $host): array
    {
        if ($this->resolve) {
            return ($this->resolve)($host);
        }

        $ips = [];
        foreach ([DNS_A, DNS_AAAA] as $type) {
            foreach (@dns_get_record($host, $type) ?: [] as $record) {
                $ips[] = $record['ip'] ?? $record['ipv6'] ?? null;
            }
        }

        return array_values(array_filter($ips));
    }
}
