<?php

namespace App\Http\Requests\Api\V2;

/** Query of `readFile` (E34 batch 5 spec §4.1). */
final class ReadFileRequest extends ApiRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            /** File relative to the home directory. */
            'path' => ['required', 'string'],
        ];
    }
}
