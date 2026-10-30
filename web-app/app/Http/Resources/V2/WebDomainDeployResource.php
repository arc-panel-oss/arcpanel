<?php

namespace App\Http\Resources\V2;

use App\Api\TaskId;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The Git Deploy of a web domain (`v-list-web-domain-deploy USER DOMAIN
 * json`, E20 §3.1, §8): its configuration, endpoint URLs and trusted
 * publishers. Secrets are never listed; the public deploy key and the
 * webhook secret appear only in the answer of the request that created them.
 *
 * @property array{user: string, domain: string, row: array<string, mixed>, credentials: array<string, string>} $resource
 */
final class WebDomainDeployResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $row = $this->resource['row'];
        $user = $this->resource['user'];
        $credentials = $this->resource['credentials'];
        $value = fn (string $key): ?string => ($v = trim((string) ($row[$key] ?? ''))) !== '' ? $v : null;
        $task = fn (string $key): ?string => ($id = $value($key)) !== null && preg_match('/^\d{14}$/', $id) === 1 ? TaskId::queue($user, $id)->apiId() : null;
        $off = ($value('STATE') ?? 'off') === 'off';
        $flag = fn (string $key): ?bool => $off || $value($key) === null ? null : $value($key) === 'yes';

        return [
            /** @var string */
            'domain' => $this->resource['domain'],
            /** @var 'off'|'new'|'active' `new` until the first deploy succeeded */
            'state' => $value('STATE') ?? 'off',
            /** @var 'pull'|'push'|null */
            'mode' => $off ? null : $value('MODE'),
            /** @var string|null the ID of the endpoint URLs; it hides the domain name */
            'id' => $off ? null : $value('ID'),
            /** @var string|null pull mode: the repository */
            'url' => $off ? null : $value('URL'),
            /** @var string|null pull mode: the branch deployed */
            'branch' => $off ? null : $value('BRANCH'),
            /** @var 'key'|'token'|null pull mode: how the server reaches the repository */
            'auth' => $off ? null : $value('AUTH'),
            /** @var 'none'|'laravel'|'static'|'custom'|null */
            'preset' => $off ? null : $value('PRESET'),
            /** @var list<string> paths linked from `shared/` into every release */
            'shared' => $off ? [] : array_values(array_filter(preg_split('/\s+/', (string) ($row['SHARED'] ?? '')) ?: [], fn (string $p) => $p !== '')),
            /** @var int|null releases kept */
            'keep' => ! $off && $value('KEEP') !== null ? (int) $value('KEEP') : null,
            /** @var string|null `.` or the folder of the release served as the document root */
            'webroot' => $off ? null : $value('WEBROOT'),
            /** @var bool|null pull mode: migrations run before the switch */
            'migrate' => $flag('MIGRATE'),
            /** @var string|null the path checked after the switch */
            'health_path' => $off ? null : $value('HEALTH_PATH'),
            /** @var string|null the release that is live (`YYYYMMDD-HHMMSS`) */
            'current_release' => $off ? null : $value('CURRENT'),
            /** @var string|null the deploy task that runs, for `getTask` */
            'running_task' => $off ? null : $task('RUNNING'),
            /** @var string|null the deploy task that waits for it (the newest request wins) */
            'pending_task' => $off ? null : $task('PENDING'),
            /** @var bool whether the domain can use deploy */
            'supported' => ($row['SUPPORTED'] ?? 'yes') !== 'no',
            /** @var 'package'|'backend'|'template'|null why it cannot */
            'reason' => $value('REASON'),
            /** @var string|null pull mode: the URL of the Git host's webhook */
            'webhook_url' => $off ? null : $value('WEBHOOK_URL'),
            /** @var string|null push mode: the URL the CI uploads to (the audience of its OIDC token) */
            'upload_url' => $off ? null : $value('UPLOAD_URL'),
            /** @var string|null the custom document root of the domain */
            'document_root' => $value('DOCROOT'),
            /** @var int|null */
            'deploy_port' => $value('DEPLOY_PORT') !== null ? (int) $value('DEPLOY_PORT') : null,
            /** @var bool whether the deploy port is open (a domain of the server uses deploy) */
            'port_open' => ($row['PORT_OPEN'] ?? 'no') === 'yes',
            /** @var list<array{id: string, issuer: string, repository: string, ref: string|null, environment: string|null, workflow: string|null}> push mode: the trusted publishers */
            'publishers' => $off ? [] : array_map(fn (array $p) => [
                'id' => (string) ($p['PUB'] ?? ''),
                'issuer' => (string) ($p['ISSUER'] ?? ''),
                'repository' => (string) ($p['SUBJECT_REPO'] ?? ''),
                'ref' => ($p['REF'] ?? '') !== '' ? (string) $p['REF'] : null,
                'environment' => ($p['ENVIRONMENT'] ?? '') !== '' ? (string) $p['ENVIRONMENT'] : null,
                'workflow' => ($p['WORKFLOW'] ?? '') !== '' ? (string) $p['WORKFLOW'] : null,
            ], array_values(array_filter((array) ($row['PUBLISHERS'] ?? []), 'is_array'))),
            /** @var string|null the public deploy key, to add to the Git host: only in the answer of the request that created the key pair */
            'public_key' => ($credentials['PUBLIC_KEY'] ?? '') !== '' ? $credentials['PUBLIC_KEY'] : null,
            /** @var string|null the webhook secret, to enter at the Git host: only in the answer of the request that created it */
            'webhook_secret' => ($credentials['WEBHOOK_SECRET'] ?? '') !== '' ? $credentials['WEBHOOK_SECRET'] : null,
        ];
    }
}
