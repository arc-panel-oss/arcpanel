<?php

namespace App\Http\Requests\Users;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Base of the user edit tab forms: errors go to the tab's own error bag and
 * the redirect returns to the tab. Authorization is done by the controller
 * with UserPolicy.
 */
abstract class UserTabRequest extends FormRequest
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
        return (string) url()->previous().'#'.$this->tab();
    }
}
