<?php

namespace App\Http\Requests\Cron;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The fields of web/add/cron and web/edit/cron: the five schedule fields and
 * the command, all required ("0" is a value). v-add-cron-job and
 * v-change-cron-job check the schedule format (is_cron_format_valid).
 */
class CronJobRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        // One line each: a crontab entry cannot hold a line break.
        $line = 'not_regex:/[\r\n\x00]/';

        return [
            'min' => ['required', 'string', 'max:128', $line],
            'hour' => ['required', 'string', 'max:128', $line],
            'day' => ['required', 'string', 'max:128', $line],
            'month' => ['required', 'string', 'max:128', $line],
            'wday' => ['required', 'string', 'max:128', $line],
            'cmd' => ['required', 'string', 'max:4096', $line],
        ];
    }

    /** @return array<string, string> the legacy message of an empty field */
    public function messages(): array
    {
        $messages = [];
        foreach ($this->attributes() as $field => $label) {
            $messages[$field.'.required'] = sprintf(__('Field "%s" can not be blank.'), $label);
        }

        return $messages;
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'min' => __('Minute'),
            'hour' => __('Hour'),
            'day' => __('Day'),
            'month' => __('Month'),
            'wday' => __('Day of Week'),
            'cmd' => __('Command'),
        ];
    }

    /** @return array{min: string, hour: string, day: string, month: string, wday: string} */
    public function schedule(): array
    {
        return [
            'min' => (string) $this->input('min'),
            'hour' => (string) $this->input('hour'),
            'day' => (string) $this->input('day'),
            'month' => (string) $this->input('month'),
            'wday' => (string) $this->input('wday'),
        ];
    }

    public function command(): string
    {
        return (string) $this->input('cmd');
    }
}
