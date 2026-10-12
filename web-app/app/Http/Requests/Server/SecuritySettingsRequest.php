<?php

namespace App\Http\Requests\Server;

use App\Services\Server\ServerSettings;

/** Security tab of the server settings (E7 batch 3 spec §8.2); ServerPolicy authorizes. */
final class SecuritySettingsRequest extends ServerSettingsRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $rules = [
            'API_SYSTEM' => ['required', 'in:0,1,2'],
            'API' => ['required', 'in:yes,no'],
            'API_ALLOWED_IP' => ['nullable', 'string', 'max:4096'],
            'LOGIN_STYLE' => ['required', 'in:default,old'],
            'INACTIVE_SESSION_TIMEOUT' => ['required', 'integer', 'min:1', 'max:1440'],
            'POLICY_CSRF_STRICTNESS' => ['required', 'in:0,1,2'],
        ];
        foreach (ServerSettings::SECURITY_POLICIES as $keys) {
            foreach ($keys as $key) {
                $rules[$key] = ['sometimes', 'in:yes,no'];
            }
        }

        return $rules;
    }

    /** One address or "allow-all" per line, as legacy (filter_var IP). */
    public function after(): array
    {
        return [function ($validator) {
            foreach ($this->allowedIps() as $ip) {
                if ($ip !== 'allow-all' && filter_var($ip, FILTER_VALIDATE_IP) === false) {
                    $validator->errors()->add('API_ALLOWED_IP', sprintf(__('%s is not a valid IP address.'), $ip));
                }
            }
        }];
    }

    /**
     * API_ALLOWED_IP is left out when the form did not send it, so a form
     * without the field keeps the current list instead of clearing it.
     *
     * @return array<string, string> key => value
     */
    public function values(): array
    {
        $values = [
            'API_SYSTEM' => (string) $this->input('API_SYSTEM'),
            'API' => (string) $this->input('API'),
            'LOGIN_STYLE' => (string) $this->input('LOGIN_STYLE'),
            'INACTIVE_SESSION_TIMEOUT' => (string) (int) $this->input('INACTIVE_SESSION_TIMEOUT'),
            'POLICY_CSRF_STRICTNESS' => (string) $this->input('POLICY_CSRF_STRICTNESS'),
        ];
        if ($this->has('API_ALLOWED_IP')) {
            $values['API_ALLOWED_IP'] = implode(',', $this->allowedIps());
        }
        foreach (ServerSettings::SECURITY_POLICIES as $keys) {
            foreach ($keys as $key) {
                if ($this->has($key)) {
                    $values[$key] = (string) $this->input($key);
                }
            }
        }

        return $values;
    }

    /** @return list<string> */
    private function allowedIps(): array
    {
        $lines = preg_split('/[\s,]+/', (string) $this->input('API_ALLOWED_IP', '')) ?: [];

        return array_values(array_unique(array_filter(array_map('trim', $lines), fn ($ip) => $ip !== '')));
    }
}
