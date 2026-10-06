<?php

namespace App\Services\Stats;

use App\Arc\ArcCommand;

/**
 * Monthly statistics (web/list/stats): one account, the server totals, or
 * the totals of a reseller's tree (E7 batch 2 spec §9.4).
 */
final class StatsService
{
    public function __construct(private readonly ArcCommand $arc) {}

    /** @return list<StatsMonth> `v-list-user-stats USER json`, newest first */
    public function forUser(string $user): array
    {
        return self::months($this->arc->json('v-list-user-stats', [$user]));
    }

    /** @return list<StatsMonth> `v-list-users-stats json`, newest first */
    public function overall(): array
    {
        return self::months($this->arc->json('v-list-users-stats'));
    }

    /** @return list<StatsMonth> `v-list-reseller-users-stats RESELLER json`, newest first */
    public function tree(string $reseller): array
    {
        return self::months($this->arc->json('v-list-reseller-users-stats', [$reseller]));
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return list<StatsMonth>
     */
    private static function months(array $data): array
    {
        $months = [];
        foreach ($data as $date => $row) {
            if (is_array($row)) {
                $months[] = StatsMonth::fromArray((string) $date, $row);
            }
        }
        usort($months, fn (StatsMonth $a, StatsMonth $b) => strcmp($b->date, $a->date));

        return $months;
    }
}
