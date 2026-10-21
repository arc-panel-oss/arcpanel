<?php

namespace App\Services\Ports;

use App\Services\WebDomains\ApplyResult;
use App\Tenancy\TenancyDirectory;

/**
 * Validation, range parsing, owner lookups and result messages of the port
 * actions, shared by the session pages (PortController, ServerPortController)
 * and API v2 (E34 batch 4 spec D45, D47).
 */
final class PortActions
{
    /** A port or a range, as the commands take it */
    public const RANGE = '/^\d{1,5}(-\d{1,5})?$/';

    public const COMMENT = '/^[[:alnum:]][[:alnum:] ._-]{0,64}[[:alnum:]]$/D';

    public const USER = '/^[[:alnum:]][-.\w]{0,28}[[:alnum:]]$/';

    public const PROTOS = ['tcp', 'udp'];

    public function __construct(
        private readonly PortService $ports,
        private readonly TenancyDirectory $tenancy,
    ) {}

    /** @return array<string, mixed> reserve a port of the own pool */
    public function reserveRules(): array
    {
        return [
            'port' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'proto' => ['required', 'in:'.implode(',', self::PROTOS)],
            'comment' => ['nullable', 'string', 'regex:'.self::COMMENT],
        ];
    }

    /** @return array<string, mixed> "Request more ports" */
    public function requestRules(): array
    {
        return [
            'count' => ['required', 'integer', 'min:1', 'max:1000'],
            'reason' => ['nullable', 'string', 'max:200', 'not_regex:/[<>\r\n]/'],
        ];
    }

    /** @return array<string, mixed> the give form of the pages: a range or a count by `mode` */
    public function giveFormRules(): array
    {
        return [
            'mode' => ['required', 'in:range,count'],
            'range' => ['nullable', 'required_if:mode,range', 'string', 'regex:'.self::RANGE],
            'count' => ['nullable', 'required_if:mode,count', 'integer', 'min:1', 'max:10000'],
        ];
    }

    /** @return array<string, mixed> givePorts of API v2: the customer and a range or a count */
    public function giveRules(): array
    {
        return [
            'to' => ['required', 'string', 'regex:'.self::USER],
            ...$this->rangeOrCountRules(),
        ];
    }

    /** @return array<string, mixed> the allocate form of Server → Ports */
    public function allocateFormRules(): array
    {
        return [
            'user' => ['required', 'string', 'regex:'.self::USER],
            'mode' => ['required', 'in:range,count'],
            'range' => ['nullable', 'required_if:mode,range', 'string', 'regex:'.self::RANGE],
            'count' => ['nullable', 'required_if:mode,count', 'integer', 'min:1', 'max:10000'],
            'public_ok' => ['nullable', 'boolean'],
            'comment' => ['nullable', 'string', 'regex:'.self::COMMENT],
        ];
    }

    /** @return array<string, mixed> allocatePorts of API v2 */
    public function allocateRules(): array
    {
        return [
            'user' => ['required', 'string', 'regex:'.self::USER],
            ...$this->rangeOrCountRules(),
            'public_ok' => ['nullable', 'boolean'],
            'comment' => ['nullable', 'string', 'regex:'.self::COMMENT],
        ];
    }

    /** @return array<string, mixed> the server port range */
    public function serverRangeRules(): array
    {
        return [
            'start' => ['required', 'integer', 'min:1024', 'max:65534'],
            'end' => ['required', 'integer', 'gt:start', 'max:65535'],
        ];
    }

    /** @return array<string, mixed> a reserved port or range of the server */
    public function reservedRules(): array
    {
        return [
            'range' => ['required', 'string', 'regex:'.self::RANGE],
            'comment' => ['nullable', 'string', 'regex:'.self::COMMENT],
        ];
    }

    /** @return array<string, mixed> an orphan socket registered to its account */
    public function registerRules(): array
    {
        return [
            'user' => ['required', 'string', 'regex:'.self::USER],
            'port' => ['required', 'integer', 'min:1', 'max:65535'],
            'proto' => ['required', 'in:'.implode(',', self::PROTOS)],
        ];
    }

    /** @return array<string, mixed> an unmanaged firewall rule linked to its port */
    public function adoptRules(): array
    {
        return [
            'port' => ['required', 'integer', 'min:1', 'max:65535'],
            'rule' => ['required', 'integer', 'min:1'],
        ];
    }

    /** @return array<string, mixed> */
    public function closeRuleRules(): array
    {
        return ['rule' => ['required', 'integer', 'min:1']];
    }

    /**
     * The range or the count of a validated allocation or give: the form
     * names it with `mode`, the API sends one of the two.
     *
     * @param  array<string, mixed>  $data
     * @return array{0: ?string, 1: ?int}
     */
    public function rangeOrCount(array $data): array
    {
        $mode = $data['mode'] ?? (isset($data['range']) ? 'range' : 'count');

        return $mode === 'range' ? [(string) $data['range'], null] : [null, (int) $data['count']];
    }

    /** What v-change-user-port-owner moves: a range, or +COUNT lowest free ports. */
    public function moveSpec(?string $range, ?int $count): string
    {
        return $range ?? '+'.(int) $count;
    }

    /**
     * Start and end of PORT or START-END; null when it is no valid range
     * (a port outside 1–65535, or an end below the start).
     *
     * @return array{0: int, 1: int}|null
     */
    public function bounds(string $range): ?array
    {
        if (preg_match(self::RANGE, $range) !== 1) {
            return null;
        }
        [$start, $end] = PortService::bounds($range);

        return $start >= 1 && $end <= 65535 && $start <= $end ? [$start, $end] : null;
    }

    /** Whether every port of $range belongs to $account. */
    public function holds(PortAccount $account, string $range): bool
    {
        $bounds = $this->bounds($range);

        return $bounds !== null && $account->holds(...$bounds);
    }

    /** The single-port entry named by $range (and $proto when given), or null. */
    public function entry(PortAccount $account, string $range, ?string $proto = null): ?PortEntry
    {
        $bounds = $this->bounds($range);
        if ($bounds === null || $bounds[0] !== $bounds[1]) {
            return null;
        }
        $entry = $account->entry($bounds[0]);

        return $entry !== null && ($proto === null || $entry->proto === $proto) ? $entry : null;
    }

    public function userExists(string $user): bool
    {
        return $this->tenancy->exists($user);
    }

    /** The account whose pool gives ports to $user and takes them back. */
    public function ownerOf(string $user): string
    {
        return $this->tenancy->ownerOf($user);
    }

    /** The assigned single port that holds $port, the only kind a rule can be linked to; null otherwise. */
    public function adoptable(int $port): ?PortEntry
    {
        $holder = $this->ports->registry()->holderOf($port);

        return $holder !== null && $holder->assigned() && ! $holder->isRange() ? $holder : null;
    }

    /**
     * The flash messages of a run on the pages: `ok`, or `error` naming the
     * failed step with the command's message as `error_detail`.
     *
     * @return array<string, string>
     */
    public function flash(ApplyResult $result, string $ok): array
    {
        return $result->ok()
            ? ['ok' => $ok]
            : ['error' => sprintf(__('%s failed'), $result->failed?->label), 'error_detail' => $result->error];
    }

    /** @return array<string, mixed> */
    private function rangeOrCountRules(): array
    {
        return [
            'range' => ['nullable', 'required_without:count', 'prohibits:count', 'string', 'regex:'.self::RANGE],
            'count' => ['nullable', 'required_without:range', 'integer', 'min:1', 'max:10000'],
        ];
    }
}
