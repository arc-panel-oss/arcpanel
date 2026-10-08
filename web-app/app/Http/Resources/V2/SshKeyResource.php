<?php

namespace App\Http\Resources\V2;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An SSH key of a user's authorized_keys (`v-list-user-ssh-key USER json`):
 * its comment, which identifies it, and its fingerprint.
 *
 * @property array{id: string, fingerprint: string} $resource
 */
final class SshKeyResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            /** @var string the key's comment; URL-encode it in paths */
            'id' => $this->resource['id'],
            /** @var string as `ssh-keygen -l` prints it, for example `SHA256:…` */
            'fingerprint' => $this->resource['fingerprint'],
        ];
    }
}
