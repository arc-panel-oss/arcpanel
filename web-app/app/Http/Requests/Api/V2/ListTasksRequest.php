<?php

namespace App\Http\Requests\Api\V2;

use Illuminate\Foundation\Http\FormRequest;

/** Query parameters of `listTasks` (E15 spec §7.1). */
final class ListTasksRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'status' => ['sometimes', 'in:queued,running,succeeded,failed,cancelled'],
            'type' => ['sometimes', 'string', 'max:64', 'regex:/^[a-z0-9-]+(\.[a-z0-9-]+)*(\.\*)?$/'],
            'user' => ['sometimes', 'string', 'max:64', 'regex:/^[A-Za-z0-9._-]+$/'],
            'since' => ['sometimes', 'date'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:1000'],
            'offset' => ['sometimes', 'integer', 'min:0'],
        ];
    }

    /** @return array{status?: string, type?: string, user?: string, since?: string} */
    public function filters(): array
    {
        $valid = $this->validated();
        $filters = [];
        foreach (['status', 'type', 'user'] as $key) {
            if (isset($valid[$key])) {
                $filters[$key] = (string) $valid[$key];
            }
        }
        if (isset($valid['since'])) {
            $filters['since'] = date('Y-m-d H:i:s', (int) strtotime((string) $valid['since']));
        }

        return $filters;
    }
}
