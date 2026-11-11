<?php

declare(strict_types=1);

namespace ElSchneider\ContentMcp\Content;

use Closure;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\TransferException;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\FnStream;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Psr\Http\Message\ResponseInterface;

/**
 * Downloads a file from a URL the model supplies without letting it reach the
 * server's own network (SSRF): only http(s), only hosts whose every address is
 * public, the connection pinned to the checked address so DNS cannot change it
 * in between, each redirect checked the same way, and the size capped while the
 * body streams in.
 */
final class RemoteFile
{
    private const int MAX_REDIRECTS = 3;

    /**
     * Ranges a request must never reach: private, loopback, link-local, CGNAT,
     * multicast, documentation and other reserved or special-purpose ranges.
     */
    private const array BLOCKED = [
        '0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '169.254.0.0/16', '172.16.0.0/12',
        '192.0.0.0/24', '192.0.2.0/24', '192.88.99.0/24', '192.168.0.0/16', '198.18.0.0/15',
        '198.51.100.0/24', '203.0.113.0/24', '224.0.0.0/4', '240.0.0.0/4',
        // IPv6. IPv4-mapped addresses (::ffff:0:0/96) are checked as the IPv4 address they carry.
        '::/96', '64:ff9b::/96', '64:ff9b:1::/48', '100::/64', '2001::/23', '2001:db8::/32', '2002::/16',
        'fc00::/7', 'fe80::/10', 'fec0::/10', 'ff00::/8',
    ];

    /** @var Closure(string): list<string> */
    private Closure $resolve;

    private Client $client;

    /**
     * @param  (Closure(string): list<string>)|null  $resolve  the addresses a host name resolves to
     * @param  callable|null  $handler  a Guzzle handler; tests pass a MockHandler
     */
    public function __construct(?Closure $resolve = null, ?callable $handler = null)
    {
        $this->resolve = $resolve ?? self::dns(...);
        $this->client = new Client(['handler' => HandlerStack::create($handler)]);
    }

    public static function blocked(string $ip): bool
    {
        $binary = @inet_pton($ip);

        if ($binary === false) {
            return true;
        }

        // An IPv4-mapped IPv6 address reaches the IPv4 address it carries.
        if (strlen($binary) === 16 && str_starts_with($binary, str_repeat("\0", 10)."\xff\xff")) {
            $binary = substr($binary, 12);
        }

        foreach (self::BLOCKED as $range) {
            [$network, $bits] = explode('/', $range);
            $prefix = (string) inet_pton($network);

            if (strlen($prefix) === strlen($binary) && self::inPrefix($binary, $prefix, (int) $bits)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return UploadedFile a temporary file, named after the last path segment of the URL
     */
    public function download(string $url, int $maxBytes): UploadedFile
    {
        // Without curl, Guzzle falls back to PHP streams, which ignore the pinned address.
        if (! extension_loaded('curl')) {
            throw self::invalid('Uploading from a URL needs the PHP curl extension on the server.');
        }

        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            $path = (string) tempnam(sys_get_temp_dir(), 'content-mcp-');

            try {
                $response = $this->get($url, $path, $maxBytes);
            } catch (ValidationException $e) {
                @unlink($path);

                throw $e;
            }

            $status = $response->getStatusCode();

            if ($status >= 200 && $status < 300) {
                return new UploadedFile($path, rawurldecode(basename((string) parse_url($url, PHP_URL_PATH))), test: true);
            }

            @unlink($path);

            if ($status < 300 || $status >= 400) {
                throw self::invalid("The URL answered with HTTP status {$status}.");
            }

            $location = $response->getHeaderLine('Location');

            if ($location === '') {
                throw self::invalid('The URL redirects without a location.');
            }

            $url = (string) UriResolver::resolve(new Uri($url), new Uri($location));
        }

        throw self::invalid('The URL redirects too often.');
    }

    private static function inPrefix(string $address, string $network, int $bits): bool
    {
        $bytes = intdiv($bits, 8);

        if (strncmp($address, $network, $bytes) !== 0) {
            return false;
        }

        $rest = $bits % 8;
        $mask = (0xFF << (8 - $rest)) & 0xFF;

        return $rest === 0 || (ord($address[$bytes]) & $mask) === (ord($network[$bytes]) & $mask);
    }

    /**
     * @return list<string>
     */
    private static function dns(string $host): array
    {
        $records = @dns_get_record($host, DNS_A | DNS_AAAA) ?: [];

        return array_values(array_filter(array_map(fn (array $record): mixed => $record['ip'] ?? $record['ipv6'] ?? null, $records), is_string(...)));
    }

    private static function tooLarge(int $maxBytes): ValidationException
    {
        return self::invalid("The file is larger than the allowed {$maxBytes} bytes.");
    }

    private static function invalid(string $message): ValidationException
    {
        return ValidationException::withMessages(['url' => [$message]]);
    }

    /**
     * One request, without following redirects, streamed into $path.
     */
    private function get(string $url, string $path, int $maxBytes): ResponseInterface
    {
        [$host, $port, $ip] = $this->target($url);
        $file = Utils::streamFor(Utils::tryFopen($path, 'w+b'));
        $size = 0;

        $sink = FnStream::decorate($file, ['write' => function (string $data) use ($file, &$size, $maxBytes): int {
            $size += strlen($data);

            // Thrown inside curl's write callback, it aborts the transfer.
            if ($size > $maxBytes) {
                throw self::tooLarge($maxBytes);
            }

            return $file->write($data);
        }]);

        try {
            return $this->client->get($url, [
                'sink' => $sink,
                'allow_redirects' => false,
                'http_errors' => false,
                'connect_timeout' => 5,
                'timeout' => 30,
                // No proxy: it would resolve the host again.
                'proxy' => '',
                'headers' => ['User-Agent' => 'Statamic Content MCP'],
                'on_headers' => function (ResponseInterface $response) use ($maxBytes): void {
                    if ((int) $response->getHeaderLine('Content-Length') > $maxBytes) {
                        throw self::tooLarge($maxBytes);
                    }
                },
                // Connect to the address that was checked, never to a fresh lookup.
                'curl' => [CURLOPT_RESOLVE => ["{$host}:{$port}:".(str_contains($ip, ':') ? "[{$ip}]" : $ip)]],
            ]);
        } catch (TransferException $e) {
            throw $e->getPrevious() instanceof ValidationException ? $e->getPrevious() : self::invalid('The URL could not be loaded.');
        } finally {
            $file->close();
        }
    }

    /**
     * @return array{0: string, 1: int, 2: string} host, port and the public address to connect to
     */
    private function target(string $url): array
    {
        $parts = parse_url($url);
        $scheme = strtolower(is_array($parts) ? ($parts['scheme'] ?? '') : '');
        $host = strtolower(trim(is_array($parts) ? ($parts['host'] ?? '') : '', '[]'));

        if (! is_array($parts) || ! in_array($scheme, ['http', 'https'], true) || $host === '' || isset($parts['user']) || isset($parts['pass'])) {
            throw self::invalid('Only http and https URLs without credentials can be uploaded.');
        }

        $literal = filter_var($host, FILTER_VALIDATE_IP) !== false;

        // curl reads numeric spellings such as 2130706433, 0x7f.1 or 0177.0.0.1 as IP
        // addresses and then ignores the pin, and connects to something else for a trailing
        // dot or a name it converts (IDN). Only standard IP literals and plain ASCII domain
        // names, whose last label starts with a letter, reach curl.
        if (! $literal && preg_match('/^([a-z0-9_]([a-z0-9_-]*[a-z0-9_])?\.)*[a-z]([a-z0-9-]*[a-z0-9])?$/', $host) !== 1) {
            throw self::invalid("The host [{$host}] is not a domain name or a standard IP address.");
        }

        $ips = $literal ? [$host] : ($this->resolve)($host);

        if ($ips === []) {
            throw self::invalid("The host [{$host}] could not be resolved.");
        }

        // Every address must be public, or a later lookup could pick a private one.
        foreach ($ips as $ip) {
            if (self::blocked($ip)) {
                throw self::invalid("The host [{$host}] is not a public address.");
            }
        }

        return [$host, $parts['port'] ?? ($scheme === 'https' ? 443 : 80), $ips[0]];
    }
}
