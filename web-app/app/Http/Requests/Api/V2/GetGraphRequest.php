<?php

namespace App\Http\Requests\Api\V2;

use App\Services\Server\RrdService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Query parameters of `getGraph` (E34 batch 6 spec D65). */
final class GetGraphRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            /** The time span of the series: a day (default), a week, a month, a year, two or three years. */
            'period' => ['sometimes', 'string', Rule::in(RrdService::PERIODS)],
        ];
    }

    public function period(): string
    {
        return (string) ($this->validated()['period'] ?? 'daily');
    }
}
