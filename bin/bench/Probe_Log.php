<?php

/**
 * Reads the JSON lines bin/bench/probe.php appends to its log, by request id.
 *
 * REPO-ONLY, part of bin/bench (see bin/bench/run.php).
 */

declare(strict_types=1);

namespace WPMCP\Bench;

final class Probe_Log
{
    private int $offset = 0;

    /** @var array<string, array<string, mixed>> */
    private array $pending = [];

    public function __construct(private readonly string $path)
    {
        clearstatcache(true, $path);
        $this->offset = is_file($path) ? (int) filesize($path) : 0;
    }

    /** @return array<string, mixed>|null */
    public function find(string $id): ?array
    {
        for ($try = 0; $try < 50; $try++) {
            $this->read();
            if (isset($this->pending[ $id ])) {
                $record = $this->pending[ $id ];
                unset($this->pending[ $id ]);
                return $record;
            }
            usleep(10000);
        }
        return null;
    }

    private function read(): void
    {
        clearstatcache(true, $this->path);
        if (! is_file($this->path) || (int) filesize($this->path) <= $this->offset) {
            return;
        }
        $fh = fopen($this->path, 'rb');
        if (false === $fh) {
            return;
        }
        fseek($fh, $this->offset);
        $chunk = (string) stream_get_contents($fh);
        fclose($fh);
        $last = strrpos($chunk, "\n");
        if (false === $last) {
            return;
        }
        $this->offset += $last + 1;
        foreach (explode("\n", substr($chunk, 0, $last)) as $line) {
            $record = json_decode($line, true);
            if (is_array($record) && is_string($record['id'] ?? null) && '' !== $record['id']) {
                $this->pending[ $record['id'] ] = $record;
            }
        }
    }
}
