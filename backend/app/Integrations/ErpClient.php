<?php

namespace App\Integrations;

use App\Models\ErpConnection;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Calls a manufacturer's ERP API for the ERP connection. Every call:
 *
 * - goes only to a public https address (UrlGuard, checked before each call,
 *   like webhooks), and never follows a redirect, which could lead somewhere
 *   the guard never checked;
 * - carries their token in the header they chose, and only to their own
 *   host: a "next page" link pointing anywhere else is refused, so the token
 *   can't be handed to a third party;
 * - waits at most 30 seconds, and refuses a reply over 10 MB.
 *
 * Problems come back as ErpException with a sentence the Integrations page shows as is.
 */
class ErpClient
{
    private const MAX_BYTES = 10 * 1024 * 1024;

    public function __construct(private ErpConnection $connection, private UrlGuard $guard) {}

    /** A path from their settings ("/items?since={since}") as a full address on their ERP. */
    public function url(string $path, array $values = []): string
    {
        foreach ($values as $name => $value) {
            $path = str_replace('{'.$name.'}', rawurlencode((string) $value), $path);
        }
        if (preg_match('#^https?://#i', $path)) {
            return $path;
        }

        return rtrim($this->connection->base_url, '/').'/'.ltrim($path, '/');
    }

    /** @throws ErpException */
    public function get(string $url): mixed
    {
        return $this->json($this->send('GET', $url));
    }

    /** "Test the connection": the status of a GET, whatever the reply is. @throws ErpException */
    public function check(string $url): int
    {
        return $this->send('GET', $url)->status();
    }

    /** @throws ErpException */
    public function post(string $url, array $body, array $headers = []): Response
    {
        return $this->send('POST', $url, $body, $headers);
    }

    /**
     * Every item from a list endpoint, following their "next page" links
     * (`next`, a dot path to a full address or a path on their ERP) - up to
     * 50 pages, so a link that points back at itself can't loop forever.
     *
     * @return list<mixed>
     *
     * @throws ErpException
     */
    public function all(string $url, ?string $listPath, ?string $nextPath, int $maxPages = 50): array
    {
        $items = [];
        $seen = [];
        for ($page = 1; $page <= $maxPages; $page++) {
            $seen[$url] = true;
            $body = $this->get($url);
            $items = array_merge($items, self::listIn($body, $listPath));

            $next = $nextPath ? self::pick($body, $nextPath) : null;
            if (! is_string($next) || trim($next) === '') {
                return $items;
            }
            $url = $this->url(trim($next));
            if (isset($seen[$url])) {
                return $items;
            }
        }

        throw new ErpException("Stopped after {$maxPages} pages. Ask your ERP for fewer items at a time, or check the next-page setting.");
    }

    /** The list in a reply: at `listPath`, or the reply itself when that's empty. */
    public static function listIn(mixed $body, ?string $listPath): array
    {
        $list = ($listPath === null || $listPath === '') ? $body : self::pick($body, $listPath);
        if (! is_array($list) || ! array_is_list($list)) {
            // Name what the reply does have, so finding the list isn't guesswork.
            $keys = is_array($body) && ! array_is_list($body) ? array_slice(array_keys($body), 0, 15) : [];
            $has = $keys ? ' The reply has: '.implode(', ', $keys).'.' : '';

            throw new ErpException(($listPath
                ? "There's no list at \"{$listPath}\" in your ERP's reply. Check where the list is."
                : 'Your ERP\'s reply isn\'t a list. Say where the list is in the reply (e.g. "data" or "value").').$has);
        }

        return $list;
    }

    /**
     * A value at a dot path ("Customer.No", "lines.0.item"). A key that has
     * dots in its own name ("@odata.nextLink") is found too.
     */
    public static function pick(mixed $data, ?string $path): mixed
    {
        if ($path === null || $path === '' || ! is_array($data)) {
            return null;
        }
        if (array_key_exists($path, $data)) {
            return $data[$path];
        }

        return data_get($data, $path);
    }

    /** @throws ErpException */
    private function send(string $method, string $url, ?array $body = null, array $headers = []): Response
    {
        if ($problem = $this->guard->problem($url)) {
            throw new ErpException('Address not allowed: '.$problem, 0);
        }
        if (! $this->sameHost($url)) {
            throw new ErpException('Your ERP pointed us at another address ('.parse_url($url, PHP_URL_HOST).'). We only send your token to '.parse_url($this->connection->base_url, PHP_URL_HOST).'.', 0);
        }

        $request = Http::timeout(30)
            ->connectTimeout(10)
            ->withoutRedirecting()
            ->acceptJson()
            ->withHeaders(['User-Agent' => 'OEMDealerHub-ERP/1'] + $headers + $this->authHeader());

        try {
            $response = $method === 'GET' ? $request->get($url) : $request->post($url, $body ?? []);
        } catch (ConnectionException $e) {
            throw new ErpException('No answer from your ERP: '.$e->getMessage());
        }

        if (strlen($response->body()) > self::MAX_BYTES) {
            throw new ErpException('Your ERP\'s reply is over 10 MB. Ask for fewer items at a time.');
        }
        if ($response->redirect()) {
            throw new ErpException('Your ERP answered with a redirect ('.$response->status().'). Use the address it redirects to.');
        }
        if (! $response->successful()) {
            $hint = match (true) {
                in_array($response->status(), [401, 403], true) => ' Check the token and the sign-in header.',
                $response->status() === 404 => ' Check the path.',
                default => '',
            };

            throw new ErpException('Your ERP answered '.$response->status().'.'.$hint.self::said($response), $response->status());
        }

        return $response;
    }

    /**
     * The start of an error reply, so whoever is setting this up can see why
     * their ERP refused (a missing parameter, an expired token...) without
     * needing access to the ERP's own logs. Only their own staff see it.
     */
    private static function said(Response $response): string
    {
        // An HTML error page (IIS, ASP.NET...): drop its styles and scripts, or they're all you'd see.
        $body = preg_replace('#<(style|script|head)\b[^>]*>.*?</\1>#is', ' ', $response->body()) ?? $response->body();
        $text = trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags(str_replace('<', ' <', $body)))) ?? '');
        if ($text === '') {
            return '';
        }

        return ' It said: '.(mb_strlen($text) > 300 ? mb_substr($text, 0, 300).'…' : $text);
    }

    /** @throws ErpException */
    private function json(Response $response): mixed
    {
        $data = json_decode($response->body(), true);
        if ($data === null && trim($response->body()) !== 'null') {
            throw new ErpException('Your ERP\'s reply isn\'t JSON.');
        }

        return $data;
    }

    /** @return array<string, string> */
    private function authHeader(): array
    {
        $token = (string) $this->connection->token;
        if ($token === '') {
            return [];
        }
        $prefix = trim((string) $this->connection->auth_prefix);

        return [$this->connection->auth_header ?: 'Authorization' => $prefix === '' ? $token : $prefix.' '.$token];
    }

    private function sameHost(string $url): bool
    {
        $a = parse_url($url);
        $b = parse_url($this->connection->base_url);

        return strtolower($a['host'] ?? '') === strtolower($b['host'] ?? '')
            && strtolower($a['scheme'] ?? '') === strtolower($b['scheme'] ?? '')
            && ($a['port'] ?? null) === ($b['port'] ?? null);
    }
}
