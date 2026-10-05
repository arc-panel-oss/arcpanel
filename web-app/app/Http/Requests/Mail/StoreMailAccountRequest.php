<?php

namespace App\Http\Requests\Mail;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Add a mail account (web/add/mail?domain=). The account is lower-cased;
 * the password is required, with the legacy rule, unless "Do not store
 * forwarded mail" is ticked.
 */
final class StoreMailAccountRequest extends FormRequest
{
    use MailFields;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('account'))) {
            $this->merge(['account' => strtolower(trim($this->input('account')))]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'account' => ['required', 'string', 'regex:/^[\w.-]{1,64}$/'],
            'password' => ['nullable', 'required_without:fwd_only', 'string', 'max:256', self::LINE,
                Rule::when(! $this->boolean('fwd_only'), [self::passwordRule()])],
            ...self::quotaRules(),
            'aliases' => ['nullable', 'string', 'max:10000'],
            'forward' => ['nullable', 'string', 'max:10000'],
            'blackhole' => ['nullable', 'boolean'],
            'fwd_only' => ['nullable', 'boolean'],
            'rate' => ['nullable', 'regex:/^\d{1,9}$/'],
            'email' => ['nullable', 'email:filter', 'max:254'],
        ];
    }

    /**
     * @return array{account: string, password: string, quota: string, aliases: list<string>, forwards: list<string>, blackhole: bool, fwd_only: bool, rate: string|null}
     */
    public function values(bool $rateLimit): array
    {
        return [
            'account' => $this->text('account'),
            'password' => (string) $this->input('password', ''),
            'quota' => $this->quota(),
            'aliases' => $this->entries('aliases'),
            'forwards' => $this->boolean('blackhole') ? [] : $this->entries('forward'),
            'blackhole' => $this->boolean('blackhole'),
            'fwd_only' => $this->boolean('fwd_only'),
            'rate' => $rateLimit ? $this->text('rate') : null,
        ];
    }
}
