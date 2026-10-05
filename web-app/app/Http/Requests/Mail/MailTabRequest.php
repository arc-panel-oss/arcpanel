<?php

namespace App\Http\Requests\Mail;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Base of the tab forms of the mail domain and mail account edit pages:
 * errors go to the tab's own error bag and the redirect returns to the tab
 * (URL hash), so tabs never mix their errors. Authorization is done by the
 * controller with MailPolicy.
 */
abstract class MailTabRequest extends FormRequest
{
    use MailFields;

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
        $params = ['user' => $this->route('user'), 'domain' => $this->route('domain')];
        $account = $this->route('account');

        return (is_string($account)
            ? route('mail.accounts.edit', $params + ['account' => $account])
            : route('mail.edit', $params)).'#'.$this->tab();
    }
}
