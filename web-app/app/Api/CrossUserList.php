<?php

namespace App\Api;

use App\Arc\ArcCommand;
use App\Auth\OwnerScope;
use App\Auth\Principal;
use App\Auth\Role;
use App\Tenancy\TenancyDirectory;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The cross-user lists of E43 B2 (docs/features/specs/2026-10-16-004-e43-api-v2-fleet-additions-design.md §5):
 * one `v-list-sys-<kind> OWNER UPDATED_SINCE json` call per request. An
 * administrator's key lists every user, a reseller's key itself and its
 * customers (the command scopes OWNER as tenancy.conf does); a user's key
 * is refused, because the per-user lists cover it. Items keep the schema of
 * the per-user list and are sorted by user, then name.
 */
final class CrossUserList
{
    /** An ISO 8601 date and time; without an offset it is UTC. */
    public const ISO_8601 = '/^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})(:(\d{2})(\.\d{1,6})?)?(Z|[+-]\d{2}:?\d{2})?$/';

    public function __construct(
        private readonly ArcCommand $arc,
        private readonly Paginates $pages,
        private readonly Principal $principal,
        private readonly OwnerScope $scope,
        private readonly TenancyDirectory $directory,
    ) {}

    /**
     * @template T of object
     *
     * @param  string  $command  the `v-list-sys-<kind>` command
     * @param  Closure(string, string, array<string, mixed>): T  $record  an item from its user, name and row
     * @param  Closure(T): (int|string)  $name  the name an item is sorted by within its user
     * @return array{items: list<T>, meta: array<string, mixed>}
     */
    public function page(Request $request, string $command, Closure $record, Closure $name): array
    {
        $role = $this->principal->actingRole();
        abort_unless($role === Role::Admin || $role === Role::Reseller, 403);
        $query = $request->validate([
            'user' => ['sometimes', 'string', 'regex:/^[[:alnum:]][-.\w]{0,28}[[:alnum:]]$/'],
            'updated_since' => ['sometimes', 'string', 'max:40', function (string $attribute, mixed $value, Closure $fail): void {
                if (self::timestamp($value) === null) {
                    $fail(__('Use an ISO 8601 time, for example 2026-10-17T08:00:00Z.'));
                }
            }],
        ]);
        $user = isset($query['user']) ? (string) $query['user'] : null;
        if ($user !== null && (! $this->directory->exists($user) || ! $this->scope->canSee($user))) {
            throw new NotFoundHttpException;
        }
        $since = isset($query['updated_since']) ? (string) self::timestamp($query['updated_since']) : '';
        // One user narrows the command to it (and, for a reseller, its customers, dropped below)
        $owner = $user ?? ($role === Role::Admin ? '' : $this->principal->effectiveUser());

        $answer = $this->arc->json($command, [$owner, $since]);
        $keep = fn (string $name): bool => ($user === null || $name === $user) && $this->scope->canSee($name);
        $users = array_values(array_filter(self::names($answer['USERS'] ?? []), $keep));
        $changed = array_values(array_filter(self::names($answer['CHANGED_USERS'] ?? []), $keep));

        $rows = [];
        foreach ($changed as $owner) {
            $objects = $answer['OBJECTS'][$owner] ?? [];
            foreach (is_array($objects) ? $objects : [] as $key => $row) {
                if (is_array($row)) {
                    $item = $record($owner, (string) $key, $row);
                    $rows[] = [$owner, $name($item), $item];
                }
            }
        }
        usort($rows, fn (array $a, array $b) => strcmp($a[0], $b[0])
            ?: (is_int($a[1]) && is_int($b[1]) ? $a[1] <=> $b[1] : strcmp((string) $a[1], (string) $b[1])));

        $page = $this->pages->slice($request, array_column($rows, 2));
        $asOf = (string) ($answer['AS_OF'] ?? '');

        return ['items' => $page['items'], 'meta' => $page['meta'] + [
            'as_of' => ctype_digit($asOf) ? gmdate('Y-m-d\TH:i:s\Z', (int) $asOf) : null,
            'users' => $users,
            'changed_users' => $changed,
        ]];
    }

    /** The Unix time of an ISO 8601 time (UTC without an offset); null when it is not one. */
    public static function timestamp(mixed $value): ?int
    {
        if (! is_string($value) || preg_match(self::ISO_8601, $value, $m) !== 1) {
            return null;
        }
        if (! checkdate((int) $m[2], (int) $m[3], (int) $m[1]) || (int) $m[4] > 23 || (int) $m[5] > 59 || (int) ($m[7] ?? 0) > 59) {
            return null;
        }
        try {
            return CarbonImmutable::parse($value, 'UTC')->getTimestamp();
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return list<string> */
    private static function names(mixed $list): array
    {
        return array_values(array_map('strval', array_filter(is_array($list) ? $list : [], 'is_scalar')));
    }
}
