<?php

namespace App\Http\Requests\Users;

final class UserNameserversRequest extends UserTabRequest
{
    public function tab(): string
    {
        return 'nameservers';
    }

    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();
        $list = preg_split('/[\s,]+/', strtolower((string) $this->input('nameservers'))) ?: [];
        $this->merge(['ns' => array_values(array_filter($list))]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'ns' => ['required', 'array', 'min:2', 'max:8'],
            'ns.*' => ['string', 'max:253', 'regex:/^([a-z0-9]([a-z0-9-]*[a-z0-9])?\.)+[a-z0-9-]+$/'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['ns' => __('Name servers'), 'ns.*' => __('Name server')];
    }

    /** @return list<string> */
    public function values(): array
    {
        return array_values((array) $this->input('ns', []));
    }
}
