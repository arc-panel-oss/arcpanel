<?php

namespace App\Http\Requests\Api\V2;

/**
 * POST /system/doctor/run (E17 spec §6).
 */
final class RunSystemDoctorRequest extends ApiRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            /** Also every domain of every user (the nightly run); the server checks only when left out. */
            'all' => ['sometimes', 'boolean'],
        ];
    }
}
