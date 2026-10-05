<?php

namespace App\Http\Requests\WebDomains;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Base of the edit tab forms: errors go to the tab's own error bag and the
 * redirect returns to the tab (URL hash), so tabs never mix their errors.
 * Authorization is done by the controller with WebDomainPolicy.
 */
abstract class TabRequest extends FormRequest
{
    /** Tab name: error bag and URL hash. */
    abstract public function tab(): string;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->errorBag = $this->tab();
    }

    protected function getRedirectUrl(): string
    {
        return route('web.domains.edit', [
            'user' => $this->route('user'),
            'domain' => $this->route('domain'),
        ]).'#'.$this->tab();
    }
}
