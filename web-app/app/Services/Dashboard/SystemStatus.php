<?php

namespace App\Services\Dashboard;

/**
 * Parses the plain output of v-list-sys-cpu-status (top), -memory-status
 * (free -m) and -disk-status (df -h) for the dashboard tiles.
 */
final class SystemStatus
{
    /** @return array{percent: int} CPU busy = 100 − idle of the "%Cpu(s)" line */
    public static function cpu(string $top): array
    {
        if (preg_match('/%Cpu\(s\):.*?([\d.]+)\s*id/', $top, $m) === 1) {
            return ['percent' => (int) round(100 - (float) $m[1])];
        }

        return ['percent' => 0];
    }

    /** @return array{totalMb: int, usedMb: int, percent: int, swapTotalMb: int, swapUsedMb: int} */
    public static function memory(string $free): array
    {
        $mem = preg_match('/^Mem:\s+(\d+)\s+(\d+)/m', $free, $m) === 1 ? [(int) $m[1], (int) $m[2]] : [0, 0];
        $swap = preg_match('/^Swap:\s+(\d+)\s+(\d+)/m', $free, $s) === 1 ? [(int) $s[1], (int) $s[2]] : [0, 0];

        return [
            'totalMb' => $mem[0],
            'usedMb' => $mem[1],
            'percent' => $mem[0] > 0 ? (int) round($mem[1] / $mem[0] * 100) : 0,
            'swapTotalMb' => $swap[0],
            'swapUsedMb' => $swap[1],
        ];
    }

    /** @return array{size: string, used: string, available: string, percent: int} of the / filesystem */
    public static function disk(string $df): array
    {
        foreach (explode("\n", $df) as $line) {
            $columns = preg_split('/\s+/', trim($line)) ?: [];
            if (count($columns) >= 6 && end($columns) === '/' && str_ends_with($columns[4], '%')) {
                return [
                    'size' => $columns[1],
                    'used' => $columns[2],
                    'available' => $columns[3],
                    'percent' => (int) rtrim($columns[4], '%'),
                ];
            }
        }

        return ['size' => '', 'used' => '', 'available' => '', 'percent' => 0];
    }
}
