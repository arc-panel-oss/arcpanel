<?php

namespace App\Services\Files;

use Illuminate\Validation\ValidationException;

/**
 * What a fetch downloads (upload from URL, docs/features/specs/2026-10-22-001-e3-upload-from-url-design.md
 * §6.3): the URL, headers, authentication and an optional file name. The
 * rules mirror the request file of v-add-fs-fetch (§4.1), so a mistake is a
 * field error instead of a failed command. The request file holds secrets:
 * it is passed with Secret::file and never logged.
 */
final class FetchRequest
{
    public const URL_MAX = 8192;

    public const HEADERS_MAX = 50;

    public const AUTH_TYPES = ['none', 'basic', 'bearer'];

    /** Headers the request cannot set (§4.1) */
    public const REFUSED_HEADERS = ['host', 'content-length', 'transfer-encoding', 'connection', 'range'];

    /**
     * @param  list<array{name: string, value: string}>  $headers
     */
    public function __construct(
        public readonly string $url,
        public readonly array $headers = [],
        public readonly string $auth = 'none',
        public readonly string $username = '',
        public readonly string $password = '',
        public readonly string $token = '',
        public readonly string $name = '',
    ) {}

    /**
     * A validated request from the fields of a form or an API body:
     * url, headers (list of {name, value}), auth ({type, username,
     * password, token}) and name. Errors use the keys FIELDS maps them to.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, string>  $fields  url, headers, auth, name => the key of the error
     */
    public static function from(array $data, array $fields = []): self
    {
        $key = fn (string $field) => $fields[$field] ?? $field;
        $errors = [];

        $url = trim(is_string($data['url'] ?? null) ? $data['url'] : '');
        if ($problem = self::urlProblem($url)) {
            $errors[$key('url')] = $problem;
        }

        $headers = [];
        $rows = $data['headers'] ?? [];
        if (! is_array($rows)) {
            $errors[$key('headers')] = __('Enter the headers as a list of names and values.');
            $rows = [];
        }
        foreach (array_values($rows) as $i => $row) {
            $name = trim(is_array($row) && is_string($row['name'] ?? null) ? $row['name'] : '');
            $value = trim(is_array($row) && is_scalar($row['value'] ?? null) ? (string) $row['value'] : '');
            if ($name === '' && $value === '') {
                continue;
            }
            if (preg_match('/^[!#$%&\'*+.^_`|~0-9A-Za-z-]+$/', $name) !== 1) {
                $errors[$key('headers').".$i.name"] = __('Enter a valid header name.');
            } elseif (in_array(strtolower($name), self::REFUSED_HEADERS, true)) {
                $errors[$key('headers').".$i.name"] = sprintf(__('The header %s cannot be set.'), $name);
            }
            if (preg_match('/[\x00\r\n]/', $value) === 1 || strlen($name.$value) > 8000) {
                $errors[$key('headers').".$i.value"] = __('Enter a valid header value.');
            }
            $headers[] = ['name' => $name, 'value' => $value];
        }
        if (count($headers) > self::HEADERS_MAX) {
            $errors[$key('headers')] = sprintf(__('Enter at most %d headers.'), self::HEADERS_MAX);
        }

        $auth = is_array($data['auth'] ?? null) ? $data['auth'] : [];
        $type = is_string($auth['type'] ?? null) && $auth['type'] !== '' ? $auth['type'] : 'none';
        $username = is_string($auth['username'] ?? null) ? $auth['username'] : '';
        $password = is_string($auth['password'] ?? null) ? $auth['password'] : '';
        $token = trim(is_string($auth['token'] ?? null) ? $auth['token'] : '');
        if (! in_array($type, self::AUTH_TYPES, true)) {
            $errors[$key('auth').'.type'] = __('Choose none, basic or bearer.');
        } elseif ($type === 'basic') {
            if ($username === '' || str_contains($username, ':') || self::hasControl($username)) {
                $errors[$key('auth').'.username'] = __('Enter a user name without a colon.');
            }
            if (self::hasControl($password)) {
                $errors[$key('auth').'.password'] = __('Enter a valid password.');
            }
        } elseif ($type === 'bearer' && ($token === '' || self::hasControl($token) || strlen($token) > 8000)) {
            $errors[$key('auth').'.token'] = __('Enter the token.');
        }

        $name = trim(is_string($data['name'] ?? null) ? $data['name'] : '');
        if ($name !== '' && ! FilePath::validName($name)) {
            $errors[$key('name')] = __('Enter a valid name.');
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return new self(
            $url,
            $headers,
            $type,
            $type === 'basic' ? $username : '',
            $type === 'basic' ? $password : '',
            $type === 'bearer' ? $token : '',
            $name,
        );
    }

    /** Why URL cannot be fetched, or '' */
    public static function urlProblem(string $url): string
    {
        if ($url === '') {
            return __('Enter a URL.');
        }
        if (strlen($url) > self::URL_MAX || preg_match('/[\s\x00-\x1f\x7f]/', $url) === 1) {
            return __('Enter a valid URL.');
        }
        $parts = parse_url($url);
        if ($parts === false || ! in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true) || ($parts['host'] ?? '') === '') {
            return __('Only http and https addresses can be downloaded.');
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            return __('Put the user name and password in the authentication, not in the URL.');
        }

        return '';
    }

    /** The request file of v-add-fs-fetch (§4.1) */
    public function toRequestFile(): string
    {
        $lines = ['url '.$this->url];
        if ($this->name !== '') {
            $lines[] = 'name '.$this->name;
        }
        foreach ($this->headers as $header) {
            $lines[] = 'header '.$header['name'].': '.$header['value'];
        }
        if ($this->auth === 'basic') {
            $lines[] = 'basic '.$this->username.':'.$this->password;
        } elseif ($this->auth === 'bearer') {
            $lines[] = 'bearer '.$this->token;
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * The request without secrets (a dry run): the URL without user
     * information and query, header names, the type of authentication.
     *
     * @return array{url: string, header_names: list<string>, auth: string, name: string}
     */
    public function summary(): array
    {
        return [
            'url' => self::display($this->url),
            'header_names' => array_map(fn (array $h) => $h['name'], $this->headers),
            'auth' => $this->auth,
            'name' => $this->name,
        ];
    }

    /** The display form of a URL (D12): no user information, the query as "?…" */
    public static function display(string $url): string
    {
        $parts = parse_url($url);
        if ($parts === false || ! isset($parts['host'])) {
            return '';
        }

        return strtolower($parts['scheme'] ?? 'http').'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '')
            .($parts['path'] ?? '/').(isset($parts['query']) ? '?…' : '');
    }

    private static function hasControl(string $value): bool
    {
        return preg_match('/[\x00-\x1f\x7f]/', $value) === 1;
    }
}
