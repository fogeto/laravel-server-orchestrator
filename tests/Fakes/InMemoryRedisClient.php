<?php

namespace Fogeto\ServerOrchestrator\Tests\Fakes;

final class InMemoryRedisClient
{
    /** @var array<string, string> */
    public array $strings = [];

    /** @var array<string, int> */
    public array $ttl = [];

    /** @var array<string, array<string, float|int>> */
    public array $zsets = [];

    /** @var array<string, array<string, string|int|float>> */
    public array $hashes = [];

    public function setex(string $key, int $ttl, string $value): bool
    {
        $this->strings[$key] = $value;
        $this->ttl[$key] = $ttl;

        return true;
    }

    public function zadd(string $key, float|int $score, string $member): int
    {
        $exists = isset($this->zsets[$key][$member]);
        $this->zsets[$key][$member] = $score;

        return $exists ? 0 : 1;
    }

    public function expire(string $key, int $ttl): bool
    {
        $this->ttl[$key] = $ttl;

        return true;
    }

    public function zremrangebyscore(string $key, string|int|float $min, string|int|float $max): int
    {
        if (! isset($this->zsets[$key])) {
            return 0;
        }

        $minScore = $min === '-inf' ? -INF : (float) $min;
        $maxScore = $max === '+inf' ? INF : (float) $max;
        $removed = 0;

        foreach ($this->zsets[$key] as $member => $score) {
            if ($score >= $minScore && $score <= $maxScore) {
                unset($this->zsets[$key][$member]);
                $removed++;
            }
        }

        return $removed;
    }

    public function zremrangebyrank(string $key, int $start, int $stop): int
    {
        $members = $this->sortedMembers($key, false);
        $count = count($members);
        [$start, $stop] = $this->normalizeRange($count, $start, $stop);

        if ($count === 0 || $start > $stop) {
            return 0;
        }

        $removed = 0;
        foreach (array_slice($members, $start, $stop - $start + 1) as $member) {
            unset($this->zsets[$key][$member]);
            $removed++;
        }

        return $removed;
    }

    /**
     * @return array<int, string>
     */
    public function zrevrange(string $key, int $start, int $stop): array
    {
        return $this->range($key, $start, $stop, true);
    }

    /**
     * @return array<int, string>
     */
    public function zrange(string $key, int $start, int $stop): array
    {
        return $this->range($key, $start, $stop, false);
    }

    /**
     * @param  array<int, string>  $keys
     * @return array<int, string|null>
     */
    public function mget(array $keys): array
    {
        return array_map(fn (string $key): ?string => $this->strings[$key] ?? null, $keys);
    }

    public function zrem(string $key, string $member): int
    {
        if (! isset($this->zsets[$key][$member])) {
            return 0;
        }

        unset($this->zsets[$key][$member]);

        return 1;
    }

    /**
     * @param  array<int, string>|string  $keys
     */
    public function del(array|string $keys): int
    {
        $deleted = 0;
        foreach ((array) $keys as $key) {
            if (isset($this->strings[$key])) {
                unset($this->strings[$key]);
                $deleted++;
            }

            if (isset($this->zsets[$key])) {
                unset($this->zsets[$key]);
                $deleted++;
            }

            if (isset($this->hashes[$key])) {
                unset($this->hashes[$key]);
                $deleted++;
            }

            unset($this->ttl[$key]);
        }

        return $deleted;
    }

    public function hset(string $key, string $field, string|int|float $value): int
    {
        $exists = isset($this->hashes[$key][$field]);
        $this->hashes[$key][$field] = $value;

        return $exists ? 0 : 1;
    }

    public function hincrby(string $key, string $field, int $value): int
    {
        $this->hashes[$key][$field] = ((int) ($this->hashes[$key][$field] ?? 0)) + $value;

        return (int) $this->hashes[$key][$field];
    }

    public function hincrbyfloat(string $key, string $field, float|int $value): float
    {
        $this->hashes[$key][$field] = ((float) ($this->hashes[$key][$field] ?? 0)) + $value;

        return (float) $this->hashes[$key][$field];
    }

    /**
     * @return array<string, string|int|float>
     */
    public function hgetall(string $key): array
    {
        return $this->hashes[$key] ?? [];
    }

    /**
     * @return array<int, string>
     */
    private function range(string $key, int $start, int $stop, bool $descending): array
    {
        $members = $this->sortedMembers($key, $descending);
        [$start, $stop] = $this->normalizeRange(count($members), $start, $stop);

        if ($start > $stop) {
            return [];
        }

        return array_slice($members, $start, $stop - $start + 1);
    }

    /**
     * @return array<int, string>
     */
    private function sortedMembers(string $key, bool $descending): array
    {
        $members = array_keys($this->zsets[$key] ?? []);

        usort($members, function (string $left, string $right) use ($key, $descending): int {
            $leftScore = $this->zsets[$key][$left];
            $rightScore = $this->zsets[$key][$right];

            if ($leftScore === $rightScore) {
                return strcmp($left, $right);
            }

            return $descending
                ? ($rightScore <=> $leftScore)
                : ($leftScore <=> $rightScore);
        });

        return $members;
    }

    /**
     * @return array{0:int, 1:int}
     */
    private function normalizeRange(int $count, int $start, int $stop): array
    {
        if ($count === 0) {
            return [0, -1];
        }

        if ($start < 0) {
            $start = $count + $start;
        }

        if ($stop < 0) {
            $stop = $count + $stop;
        }

        $start = max(0, $start);
        $stop = min($count - 1, $stop);

        return [$start, $stop];
    }
}
