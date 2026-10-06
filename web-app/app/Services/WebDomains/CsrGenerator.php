<?php

namespace App\Services\WebDomains;

use App\Arc\ArcCommand;
use App\Arc\CommandResult;
use App\Arc\Options;

/**
 * The CSR generator of web/generate/ssl (E7 batch 3 spec §12.2): a
 * self-signed certificate, its key and a signing request from
 * `v-generate-ssl-cert`. The output holds a private key: it goes back to the
 * browser only and reaches no log (ArcCommand logs no output).
 */
final class CsrGenerator
{
    public const KEY_ALGORITHMS = ['ecdsa-256', 'ecdsa-384', 'rsa'];

    public function __construct(private readonly ArcCommand $arc) {}

    /** @return array<string, list<mixed>> validation rules of the fields */
    public static function rules(): array
    {
        $domain = 'regex:/^(?=.{1,253}$)([A-Za-z0-9]([A-Za-z0-9-]{0,61}[A-Za-z0-9])?\.)+[A-Za-z]{2,63}$/';
        $text = ['required', 'string', 'max:64', 'regex:/^[\pL\pN .,&()\'-]+$/u'];

        return [
            'domain' => ['required', 'string', $domain],
            'aliases' => ['nullable', 'string', 'max:4096'],
            'email' => ['required', 'email', 'max:254'],
            'country' => ['required', 'string', 'regex:/^[A-Za-z]{2}$/'],
            'state' => $text,
            'locality' => $text,
            'org' => $text,
            'key_algo' => ['nullable', 'in:'.implode(',', self::KEY_ALGORITHMS)],
        ];
    }

    /**
     * Aliases as the command takes them: one comma list, from lines, commas
     * or spaces; null when one of them is not a domain name.
     */
    public static function aliases(string $text): ?string
    {
        $aliases = array_values(array_unique(array_filter(preg_split('/[\s,]+/', strtolower(trim($text))) ?: [])));
        foreach ($aliases as $alias) {
            if (preg_match('/^(\*\.)?([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $alias) !== 1) {
                return null;
            }
        }

        return implode(',', $aliases);
    }

    /**
     * `v-generate-ssl-cert DOMAIN EMAIL COUNTRY STATE CITY ORG IT ALIASES json KEY_ALGO`
     *
     * @param  array<string, string>  $fields
     * @return array{result: CommandResult, crt: string, key: string, csr: string}
     */
    public function generate(array $fields, string $aliases): array
    {
        $result = $this->arc->run('v-generate-ssl-cert', [
            strtolower($fields['domain']), $fields['email'], strtoupper($fields['country']), $fields['state'], $fields['locality'],
            $fields['org'], 'IT', $aliases, 'json', $fields['key_algo'] ?: 'ecdsa-256',
        ], new Options(120, true));

        $data = $result->ok() ? json_decode($result->stdout, true) : null;
        $row = is_array($data) ? (array) (reset($data) ?: []) : [];

        return [
            'result' => $result,
            'crt' => (string) ($row['CRT'] ?? ''),
            'key' => (string) ($row['KEY'] ?? ''),
            'csr' => (string) ($row['CSR'] ?? ''),
        ];
    }
}
