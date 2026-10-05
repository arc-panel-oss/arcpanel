<?php

namespace App\Http\Requests\Mail;

use App\Services\Databases\DatabaseService;
use App\Services\WebDomains\Input;

/**
 * Field rules and messages shared by the mail forms. The v-* commands check
 * the formats (is_format_valid domain, email, host, port, account); the
 * requests keep out line breaks and give the legacy messages.
 */
trait MailFields
{
    /** No line breaks, NUL bytes or blanks in a single value. */
    protected const WORD = 'not_regex:/[\s\x00]/';

    /** No line breaks or NUL bytes (passwords may contain spaces). */
    protected const LINE = 'not_regex:/[\r\n\x00]/';

    /** @return array<string, string> */
    public function messages(): array
    {
        $messages = [
            'password.regex' => __('Password does not match the minimum requirements.'),
            'email.email' => __('Please enter a valid email address.'),
        ];
        foreach ($this->attributes() as $field => $label) {
            $messages[$field.'.required'] = sprintf(__('Field "%s" can not be blank.'), $label);
            $messages[$field.'.required_without'] = sprintf(__('Field "%s" can not be blank.'), $label);
        }

        return $messages;
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'domain' => __('Domain'),
            'account' => __('Account'),
            'password' => __('Password'),
            'catchall' => __('Catch-All Email'),
            'rate' => __('Rate Limit'),
            'quota' => __('Quota'),
            'relay_host' => __('Host'),
            'relay_port' => __('Port'),
            'relay_user' => __('Username'),
            'relay_password' => __('Password'),
            'certificate' => __('SSL Certificate'),
            'key' => __('SSL Private Key'),
            'ca' => __('SSL Certificate Authority / Intermediate'),
            'email' => __('Email login credentials to:'),
            'message' => __('Message'),
        ];
    }

    protected function text(string $field): string
    {
        return trim((string) $this->input($field, ''));
    }

    /** @return list<string> entries of a list field (newlines, commas and spaces separate them) */
    protected function entries(string $field): array
    {
        return Input::list($this->input($field));
    }

    /** The legacy password rule. */
    protected static function passwordRule(): string
    {
        return 'regex:'.DatabaseService::PASSWORD_RULE;
    }

    /** Quota in MB, "unlimited" when the box is ticked, '' when empty. */
    protected function quota(): string
    {
        return $this->boolean('quota_unlimited') ? 'unlimited' : $this->text('quota');
    }

    /** @return array<string, mixed> */
    protected static function quotaRules(): array
    {
        return [
            'quota' => ['nullable', 'regex:/^\d{1,9}$/'],
            'quota_unlimited' => ['nullable', 'boolean'],
        ];
    }

    /** @return array<string, mixed> */
    protected static function relayRules(): array
    {
        return [
            'relay' => ['nullable', 'boolean'],
            'relay_host' => ['nullable', 'string', 'max:253', self::WORD],
            'relay_port' => ['nullable', 'regex:/^\d{1,5}$/'],
            'relay_user' => ['nullable', 'string', 'max:255', self::WORD],
            'relay_password' => ['nullable', 'string', 'max:255', self::LINE],
        ];
    }

    /** @return array{relay: bool, relay_host: string, relay_port: string, relay_user: string, relay_password: string} */
    protected function relayValues(): array
    {
        return [
            'relay' => $this->boolean('relay'),
            'relay_host' => $this->text('relay_host'),
            'relay_port' => $this->text('relay_port'),
            'relay_user' => $this->text('relay_user'),
            'relay_password' => (string) $this->input('relay_password', ''),
        ];
    }
}
