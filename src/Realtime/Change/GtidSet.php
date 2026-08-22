<?php

declare(strict_types=1);

/**
 * A MySQL GTID set: per-source-UUID intervals of committed transaction numbers.
 *
 * This is what makes a resume safe across a failover. A file/position pair is
 * meaningless on a different server, but a GTID set names the transactions
 * themselves, so a replica can ask a brand-new primary for "everything except
 * what I already have".
 *
 * Text form matches MySQL's: `uuid:1-5:8,other-uuid:1-3`. Intervals are held
 * closed (inclusive of both ends) and merged on insert, so the set stays
 * compact no matter what order transactions arrive in.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Filesystem\Realtime\Change;

use PHPdot\Filesystem\Realtime\Protocol\PacketWriter;

final readonly class GtidSet
{
    /**
     * __construct.
     *
     * @param array<string,list<array{int,int}>> $intervals
     */
    private function __construct(private array $intervals = []) {}

    /**
     * Empty.
     *
     * @return self
     */
    public static function empty(): self
    {
        return new self();
    }

    /**
     * Parse MySQL's text form. Whitespace and newlines are tolerated, since
     * `@@GLOBAL.gtid_executed` wraps long sets.
     *
     * @param string $text
     *
     * @return self
     */
    public static function parse(string $text): self
    {
        $intervals = [];

        foreach (explode(',', $text) as $group) {
            $parts = array_values(array_filter(array_map(trim(...), explode(':', $group)), static fn(string $p): bool => $p !== ''));

            if (count($parts) < 2) {
                continue;
            }

            $uuid = strtolower(str_replace(["\n", "\r", ' '], '', $parts[0]));

            foreach (array_slice($parts, 1) as $range) {
                $bounds = explode('-', $range);
                $start = (int) $bounds[0];
                $intervals[$uuid][] = [$start, isset($bounds[1]) ? (int) $bounds[1] : $start];
            }
        }

        return (new self($intervals))->normalized();
    }

    /**
     * Return a set with one more transaction folded in.
     *
     * @param string $uuid
     * @param int $number
     *
     * @return self
     */
    public function with(string $uuid, int $number): self
    {
        $intervals = $this->intervals;
        $intervals[strtolower($uuid)][] = [$number, $number];

        return (new self($intervals))->normalized();
    }

    /**
     * Is empty.
     *
     * @return bool
     */
    public function isEmpty(): bool
    {
        return $this->intervals === [];
    }

    /**
     * MySQL's text form.
     *
     * @return string
     */
    public function toString(): string
    {
        $groups = [];

        foreach ($this->intervals as $uuid => $ranges) {
            $rendered = array_map(
                static fn(array $range): string => $range[0] === $range[1] ? (string) $range[0] : "{$range[0]}-{$range[1]}",
                $ranges,
            );

            $groups[] = $uuid . ':' . implode(':', $rendered);
        }

        return implode(',', $groups);
    }

    /**
     * The binary encoding COM_BINLOG_DUMP_GTID expects.
     *
     * MySQL writes interval ends exclusive on the wire while rendering them
     * inclusive in text, so every end is bumped by one here.
     *
     * @return string
     */
    public function toBinary(): string
    {
        $writer = new PacketWriter();
        $writer->uint64(count($this->intervals));

        foreach ($this->intervals as $uuid => $ranges) {
            $packed = hex2bin(str_replace('-', '', $uuid));
            $writer->bytes($packed === false ? str_repeat("\0", 16) : $packed);
            $writer->uint64(count($ranges));

            foreach ($ranges as $range) {
                $writer->uint64($range[0]);
                $writer->uint64($range[1] + 1);
            }
        }

        return $writer->toString();
    }

    /**
     * Sort and coalesce every UUID's intervals, joining ranges that touch as
     * well as those that overlap.
     *
     * @return self
     */
    private function normalized(): self
    {
        $normalized = [];

        foreach ($this->intervals as $uuid => $ranges) {
            usort($ranges, static fn(array $a, array $b): int => $a[0] <=> $b[0]);

            $merged = [];

            foreach ($ranges as $range) {
                $last = count($merged) - 1;

                if ($last >= 0 && $range[0] <= $merged[$last][1] + 1) {
                    $merged[$last][1] = max($merged[$last][1], $range[1]);

                    continue;
                }

                $merged[] = $range;
            }

            $normalized[$uuid] = array_values($merged);
        }

        ksort($normalized);

        return new self($normalized);
    }
}
