<?php

/**
 * The HTTP client of the benchmark: one curl request, timed, with the cookies of a wp-admin login kept.
 *
 * REPO-ONLY, part of bin/bench (see bin/bench/run.php).
 */

declare(strict_types=1);

namespace WPMCP\Bench;

final class Http
{
    /** @var array<string, string> */
    private array $cookies = [];

    /**
     * @param array<string, string> $headers
     * @return array{status: int, body: string, headers: array<string, string>, wall_ms: float, set_cookies: array<int, string>}
     */
    public function request(string $method, string $url, array $headers = [], ?string $body = null, bool $with_cookies = false): array
    {
        $lines = [];
        foreach ($headers as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }
        if ($with_cookies && [] !== $this->cookies) {
            $pairs = [];
            foreach ($this->cookies as $name => $value) {
                $pairs[] = $name . '=' . $value;
            }
            $lines[] = 'Cookie: ' . implode('; ', $pairs);
        }
        $received = [];
        $cookies  = [];
        $ch       = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => $lines,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT        => 120,
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$received, &$cookies): int {
                $parts = explode(':', $line, 2);
                if (2 === count($parts)) {
                    $name = strtolower(trim($parts[0]));
                    if ('set-cookie' === $name) {
                        $cookies[] = trim($parts[1]);
                    }
                    $received[ $name ] = trim($parts[1]);
                }
                return strlen($line);
            },
        ]);
        if (null !== $body) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $start  = hrtime(true);
        $output = curl_exec($ch);
        $wall   = (hrtime(true) - $start) / 1e6;

        return [
            'status'      => (int) curl_getinfo($ch, CURLINFO_HTTP_CODE),
            'body'        => is_string($output) ? $output : '',
            'headers'     => $received,
            'wall_ms'     => $wall,
            'set_cookies' => $cookies,
        ];
    }

    /** @param array<int, string> $set_cookies */
    public function keep_cookies(array $set_cookies): void
    {
        foreach ($set_cookies as $line) {
            $pair = explode(';', $line, 2)[0];
            $kv   = explode('=', $pair, 2);
            if (2 === count($kv) && 'deleted' !== $kv[1]) {
                $this->cookies[ trim($kv[0]) ] = trim($kv[1]);
            }
        }
    }

    public function has_cookies(): bool
    {
        return [] !== $this->cookies;
    }
}
