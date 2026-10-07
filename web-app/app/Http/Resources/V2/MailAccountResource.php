<?php

namespace App\Http\Resources\V2;

use App\Services\MailDomains\MailAccountRecord;
use App\Services\MailDomains\MailService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A mail account (`v-list-mail-account USER DOMAIN ACCOUNT json`).
 * The password is never returned.
 *
 * @property MailAccountRecord $resource
 */
final class MailAccountResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $account = $this->resource;
        $message = $account->autoreply ? app(MailService::class)->autoreplyMessage($account) : '';

        return [
            /** @var string the local part */
            'account' => $account->account,
            /** @var string account@domain */
            'address' => $account->email(),
            /** @var list<string> */
            'aliases' => $account->aliases,
            /** @var list<string> addresses, without the discard marker */
            'forwards' => $account->forwardAddresses(),
            /** @var bool incoming mail is not stored */
            'forward_only' => $account->fwdOnly,
            /** @var bool mail is discarded */
            'discard_all' => $account->discards(),
            /** @var string|null the autoreply text, null when it is off */
            'autoreply' => $account->autoreply && $message !== '' ? $message : null,
            /** @var int|null megabytes, null when unlimited */
            'quota_mb' => $account->unlimitedQuota() ? null : (int) $account->quota,
            /** @var int|null messages per hour, null for the system default */
            'rate_limit' => ctype_digit($account->rateLimit) ? (int) $account->rateLimit : null,
            /** @var int */
            'disk_mb' => $account->diskMb,
            /** @var bool */
            'suspended' => $account->suspended,
            /** @var string|null creation time in the server's time zone, YYYY-MM-DDTHH:MM:SS */
            'created_at' => UserResource::localTime($account->date, $account->time),
        ];
    }
}
