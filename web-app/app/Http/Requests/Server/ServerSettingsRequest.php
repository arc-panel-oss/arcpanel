<?php

namespace App\Http\Requests\Server;

use App\Auth\Principal;
use App\Policies\ServerPolicy;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Base of the server settings forms (E7 batch 3 spec §8): only principals
 * who may change the settings get as far as validation, so everyone else
 * gets 403 and not the field errors.
 */
abstract class ServerSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return app(ServerPolicy::class)->editSettings(app(Principal::class));
    }
}
