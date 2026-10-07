<?php

namespace App\Http\Requests\Api\V2;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Base of the API v2 requests: JSON bodies, authorization by the controller
 * through the policies, validation errors answered as E_VALIDATION by
 * App\Api\ErrorRenderer (never a redirect).
 */
abstract class ApiRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }
}
