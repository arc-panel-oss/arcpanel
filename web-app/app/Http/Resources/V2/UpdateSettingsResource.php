<?php

namespace App\Http\Resources\V2;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The update settings of the server (E34 batch 6 spec §5.4): the automatic
 * update switch and the e-mails after an update.
 *
 * @property array{auto_update: bool, send_email: bool, send_log: bool} $resource
 */
final class UpdateSettingsResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            /** @var bool the panel packages are updated every day */
            'auto_update' => $this->resource['auto_update'],
            /** @var bool the administrator gets an e-mail after an update */
            'send_email' => $this->resource['send_email'],
            /** @var bool that e-mail carries the log of the update */
            'send_log' => $this->resource['send_log'],
        ];
    }
}
