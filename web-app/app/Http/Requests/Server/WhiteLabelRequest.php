<?php

namespace App\Http\Requests\Server;

/** White Label tab of the server settings (E7 batch 3 spec §8.2); ServerPolicy authorizes. */
final class WhiteLabelRequest extends ServerSettingsRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $text = ['required', 'string', 'max:255', 'not_regex:/[\'"\n\r]/'];

        return [
            'APP_NAME' => $text,
            'TITLE' => $text,
            'SUBJECT_EMAIL' => $text,
            'FROM_NAME' => ['nullable', 'string', 'max:255', 'not_regex:/[\'"\n\r]/'],
            'FROM_EMAIL' => ['nullable', 'email', 'max:254'],
            'HIDE_DOCS' => ['required', 'in:yes,no'],
        ];
    }

    /** @return array<string, string> */
    public function values(): array
    {
        $values = [];
        foreach (array_keys($this->rules()) as $key) {
            $values[$key] = trim((string) $this->input($key, ''));
        }

        return $values;
    }
}
