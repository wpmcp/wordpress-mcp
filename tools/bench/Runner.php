<?php

declare(strict_types=1);

namespace WPMCP\Bench;

/**
 * Replays one scripted call sequence and counts what it cost (issue #1130).
 *
 * A sequence is a list of steps. Each step is one model turn:
 *
 *   ['tool' => 'wpmcp/list-posts', 'args' => [...] | fn(array $state): array, 'as' => 'pages']
 *   ['tool' => 'wpmcp/update-seo-meta', 'each' => fn(array $state): array[], 'as' => 'updated']
 *
 * 'args' is one call. 'each' is a fan-out: the closure returns one argument
 * list per call, all sent in the same turn (calls an agent could make in
 * parallel). A closure reads earlier results by their 'as' key, the way an
 * agent reads what came back before it decides the next call; a fan-out
 * stores the list of its results. A step whose fan-out is empty costs no
 * call and no turn.
 *
 * The transport does the call and measures it. It returns
 * ['value' => mixed, 'bytes' => int, 'error' => ?string]: the result as the
 * agent sees it, the bytes that went back to the model, and an error when
 * the call was refused or failed. The first error, or the first closure
 * that throws (a step that cannot find what it needs in earlier results),
 * stops the run as failed: a failed scenario is reported as failed, never
 * as cheap.
 *
 * REPO-ONLY, like everything under tools/: the release zips ship src/ only.
 *
 * @phpstan-type Call_Result array{value: mixed, bytes: int, error: ?string}
 * @phpstan-type Step_Record array{tool: string, via: ?string, calls: int, bytes: int, ms: float}
 * @phpstan-type Outcome array{status: string, reason: ?string, calls: int, turns: int, result_bytes: int, ms: float, steps: list<Step_Record>, state: array<string, mixed>}
 */
final class Runner
{
    /** @var \Closure(string, array<string, mixed>): Call_Result */
    private readonly \Closure $transport;

    /** @param \Closure(string, array<string, mixed>): Call_Result $transport */
    public function __construct(\Closure $transport)
    {
        $this->transport = $transport;
    }

    /**
     * @param array<int, array<string, mixed>> $steps
     * @return Outcome
     */
    public function run(array $steps): array
    {
        $out   = [
            'status'       => 'ok',
            'reason'       => null,
            'calls'        => 0,
            'turns'        => 0,
            'result_bytes' => 0,
            'ms'           => 0.0,
            'steps'        => [],
            'state'        => [],
        ];
        $start = microtime(true);
        $index = 0;

        try {
            foreach ($steps as $index => $step) {
                $out = $this->run_step($index, $step, $out);
                if ('ok' !== $out['status']) {
                    break;
                }
            }
        } catch (\Throwable $e) {
            $out['status'] = 'failed';
            $out['reason'] = sprintf('step %d: %s', $index, $e->getMessage());
        }

        $out['ms'] = round((microtime(true) - $start) * 1000, 1);
        return $out;
    }

    /**
     * @param array<string, mixed> $step
     * @param Outcome              $out
     * @return Outcome
     */
    private function run_step(int $index, array $step, array $out): array
    {
        $tool = Data::str($step, 'tool');
        if ('' === $tool) {
            throw new \InvalidArgumentException('a step needs a tool name');
        }
        $fan_out = array_key_exists('each', $step);
        if (array_key_exists('args', $step) === $fan_out) {
            throw new \InvalidArgumentException($tool . ': a step has exactly one of args or each');
        }

        $list = $fan_out
            ? $this->resolve($step['each'], $out['state'])
            : [$this->resolve($step['args'], $out['state'])];
        if (! is_array($list) || ! array_is_list($list)) {
            throw new \UnexpectedValueException($tool . ': each must return a list of argument arrays');
        }

        $label  = Data::str($step, 'label');
        $record = [
            'tool'  => '' !== $label ? $label : $tool,
            'via'   => '' !== $label ? $tool : null,
            'calls' => 0,
            'bytes' => 0,
            'ms'    => 0.0,
        ];
        $values = [];
        $began  = microtime(true);

        foreach ($list as $args) {
            if (! is_array($args)) {
                throw new \UnexpectedValueException($tool . ': arguments must be an array');
            }
            $result = ($this->transport)($tool, Data::map([$args], 0));

            $record['calls']++;
            $record['bytes']     += $result['bytes'];
            $out['calls']++;
            $out['result_bytes'] += $result['bytes'];

            if (null !== $result['error']) {
                $out['status'] = 'failed';
                $out['reason'] = sprintf('step %d (%s): %s', $index, $record['tool'], $result['error']);
                break;
            }
            $values[] = $result['value'];
        }

        $record['ms'] = round((microtime(true) - $began) * 1000, 1);
        if ($record['calls'] > 0) {
            $out['turns']++;
            $out['steps'][] = $record;
        }

        $as = Data::str($step, 'as');
        if ('' !== $as && 'ok' === $out['status']) {
            $out['state'][ $as ] = $fan_out ? $values : ($values[0] ?? null);
        }
        return $out;
    }

    /**
     * An array, or a closure of the state returning one.
     *
     * @param array<string, mixed> $state
     */
    private function resolve(mixed $spec, array $state): mixed
    {
        return $spec instanceof \Closure ? $spec($state) : $spec;
    }
}
