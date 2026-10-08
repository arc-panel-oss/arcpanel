<?php

namespace App\Http\Controllers\Api\V2;

use App\Auth\Principal;
use App\Http\Controllers\Controller;
use App\Http\Resources\V2\SearchGroupResource;
use App\Policies\SearchPolicy;
use App\Services\PaletteSearch;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Search the objects the key's owner may see (E34 batch 6 §4.3): users,
 * web, DNS and mail domains, DNS records, mail accounts, databases and
 * cron jobs, as the Search page finds them (PaletteSearch), without its
 * navigation entries.
 *
 * @tags Search
 */
final class SearchController extends Controller
{
    /** Results per type, as on the search page. */
    public const LIMIT = PaletteSearch::PAGE_LIMIT;

    /** v-search-* TYPE/KEY => the type of the answer */
    private const TYPES = [
        'web/DOMAIN' => 'web_domains',
        'dns/DOMAIN' => 'dns_zones',
        'dns/RECORD' => 'dns_records',
        'mail/DOMAIN' => 'mail_domains',
        'mail/ACCOUNT' => 'mail_accounts',
        'db/DATABASE' => 'databases',
        'cron/JOB' => 'cron_jobs',
    ];

    /**
     * Search
     *
     * Objects whose name contains `q`, grouped by type in the order users,
     * web domains, DNS zones, DNS records, mail domains, mail accounts,
     * databases, cron jobs; at most 50 per type. Types without a match are
     * left out. Users are found by name, full name or e-mail; the other
     * objects only for a single word (letters, digits, `.`, `@`, `_`, `-`).
     *
     * @operationId search
     */
    public function __invoke(Request $request, Principal $principal, SearchPolicy $policy, PaletteSearch $search): AnonymousResourceCollection
    {
        abort_unless($policy->use($principal), 403);
        $query = trim((string) $request->validate([
            /** The text to find, 2 to 100 characters. */
            'q' => ['required', 'string', 'min:2', 'max:100'],
        ])['q']);

        $groups = ['users' => []];
        foreach (self::TYPES as $type) {
            $groups[$type] = [];
        }
        foreach ($search->userRows($query) as $user) {
            $groups['users'][] = ['name' => $user['name'], 'owner' => $user['name'], 'parent' => null, 'path' => '/api/v2/users/'.rawurlencode($user['name'])];
        }
        foreach ($search->objectRows($query) as $object) {
            $type = self::TYPES[$object['type'].'/'.$object['key']] ?? null;
            $path = $type !== null ? self::path($type, $object) : null;
            if ($path === null) {
                continue;
            }
            $groups[$type][] = [
                'name' => $object['result'],
                'owner' => $object['owner'],
                'parent' => in_array($type, ['dns_records', 'mail_accounts'], true) ? $object['parent'] : null,
                'path' => $path,
            ];
        }

        $answer = [];
        foreach ($groups as $type => $items) {
            if ($items !== []) {
                $answer[] = ['type' => $type, 'items' => array_slice($items, 0, self::LIMIT)];
            }
        }

        return SearchGroupResource::collection($answer);
    }

    /** @param  array{owner: string, result: string, parent: string, link: string}  $object */
    private static function path(string $type, array $object): ?string
    {
        $user = '/api/v2/users/'.rawurlencode($object['owner']);
        $segments = match ($type) {
            'web_domains' => ['web-domains', $object['result']],
            'dns_zones' => ['dns-zones', $object['result']],
            'dns_records' => ['dns-zones', $object['parent'], 'records', $object['link']],
            'mail_domains' => ['mail-domains', $object['result']],
            'mail_accounts' => ['mail-domains', $object['parent'], 'accounts', $object['link']],
            'databases' => ['databases', $object['result']],
            'cron_jobs' => ['cron-jobs', $object['link']],
            default => [],
        };
        if ($segments === [] || in_array('', $segments, true)) {
            return null;
        }

        return $user.'/'.implode('/', array_map('rawurlencode', $segments));
    }
}
