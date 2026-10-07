<?php

namespace App\Http\Requests\Api\V2;

use Illuminate\Foundation\Http\FormRequest;

/** Query parameters of `getTask` (E34 batch 3 spec §5). */
final class GetTaskRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'lines' => ['sometimes', 'integer', 'min:1', 'max:1000'],
        ];
    }

    public function lines(): int
    {
        return (int) ($this->validated()['lines'] ?? 100);
    }
}
