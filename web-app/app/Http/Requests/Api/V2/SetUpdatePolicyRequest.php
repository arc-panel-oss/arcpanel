<?php

namespace App\Http\Requests\Api\V2;

use App\Services\Server\UpdateCenter;
use Closure;
use Illuminate\Validation\Rule;

/**
 * PATCH /system/updates/policy (E33 phase 1 spec §7.1, §9): fields left out
 * keep their value.
 */
final class SetUpdatePolicyRequest extends ApiRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            /** `off` checks nothing, `notify` announces new releases, `patch` also installs the patch releases of the installed line in the window. */
            'policy' => ['sometimes', 'string', Rule::in(UpdateCenter::POLICIES)],
            /** The days of the window. */
            'window_days' => ['sometimes', 'array', 'min:1', 'max:7'],
            'window_days.*' => ['string', 'distinct', Rule::in(UpdateCenter::DAYS)],
            /** The hours of the window, `HH-HH` in the server's time zone (start included, end excluded); `23-02` wraps past midnight, `00-24` is the whole day. */
            'window_hours' => ['sometimes', 'string', 'regex:'.UpdateCenter::HOURS_PATTERN, function (string $attribute, mixed $value, Closure $fail) {
                if (is_string($value) && preg_match(UpdateCenter::HOURS_PATTERN, $value, $m) === 1 && (int) $m[1] === (int) $m[2]) {
                    $fail(__('The window must not start and end at the same hour.'));
                }
            }],
            /** The days a release waits before the policy installs it; security releases do not wait. */
            'delay_days' => ['sometimes', 'integer', 'min:0', 'max:30'],
        ];
    }
}
