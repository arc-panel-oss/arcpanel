<?php

use App\Services\Deploy\WebhookEvent;
use App\Services\Deploy\WebhookRejected;
use App\Services\Deploy\WebhookSignature;

// The webhook check of pull-mode deploy (E20 spec §6.2).

const DEPLOY_HOOK_SECRET = 'hook-secret-for-tests';
const DEPLOY_HOOK_COMMIT = '0123456789abcdef0123456789abcdef01234567';

function deployHookPush(string $ref = 'refs/heads/main', string $after = DEPLOY_HOOK_COMMIT, array $extra = []): string
{
    return (string) json_encode(['ref' => $ref, 'before' => str_repeat('1', 40), 'after' => $after] + $extra);
}

function deployHookHmac(string $body, string $secret = DEPLOY_HOOK_SECRET): string
{
    return hash_hmac('sha256', $body, $secret);
}

beforeEach(function () {
    $this->webhooks = new WebhookSignature;
});

it('accepts a signed GitHub push and reads its ref and commit', function () {
    $body = deployHookPush();
    $event = $this->webhooks->verify([
        'X-GitHub-Event' => 'push',
        'X-Hub-Signature-256' => 'sha256='.deployHookHmac($body),
    ], $body, DEPLOY_HOOK_SECRET);

    expect($event)->toBeInstanceOf(WebhookEvent::class)
        ->and($event->provider)->toBe('github')
        ->and($event->kind)->toBe('push')
        ->and($event->ref)->toBe('refs/heads/main')
        ->and($event->commit)->toBe(DEPLOY_HOOK_COMMIT)
        ->and($event->branch())->toBe('main');
});

it('reads header names in any case and header values given as lists', function () {
    $body = deployHookPush();
    $event = $this->webhooks->verify([
        'x-github-event' => ['push'],
        'X-HUB-SIGNATURE-256' => ['sha256='.deployHookHmac($body)],
    ], $body, DEPLOY_HOOK_SECRET);

    expect($event->kind)->toBe('push');
});

it('answers a GitHub ping without a ref or commit', function () {
    $body = (string) json_encode(['zen' => 'Keep it logically awesome.', 'hook_id' => 1]);
    $event = $this->webhooks->verify([
        'X-GitHub-Event' => 'ping',
        'X-Hub-Signature-256' => 'sha256='.deployHookHmac($body),
    ], $body, DEPLOY_HOOK_SECRET);

    expect($event->provider)->toBe('github')
        ->and($event->kind)->toBe('ping')
        ->and($event->ref)->toBeNull()
        ->and($event->commit)->toBeNull();
});

it('treats other GitHub events as other', function () {
    $body = '{"action":"opened"}';
    $event = $this->webhooks->verify([
        'X-GitHub-Event' => 'pull_request',
        'X-Hub-Signature-256' => 'sha256='.deployHookHmac($body),
    ], $body, DEPLOY_HOOK_SECRET);

    expect($event->kind)->toBe('other');
});

it('refuses a GitHub push with a bad or missing signature', function (array $headers) {
    $this->webhooks->verify($headers, deployHookPush(), DEPLOY_HOOK_SECRET);
})->with([
    'wrong secret' => [['X-GitHub-Event' => 'push', 'X-Hub-Signature-256' => 'sha256='.deployHookHmac(deployHookPush(), 'other')]],
    'no prefix' => [['X-GitHub-Event' => 'push', 'X-Hub-Signature-256' => deployHookHmac(deployHookPush())]],
    'body changed' => [['X-GitHub-Event' => 'push', 'X-Hub-Signature-256' => 'sha256='.deployHookHmac(deployHookPush('refs/heads/dev'))]],
    'missing' => [['X-GitHub-Event' => 'push']],
    'empty' => [['X-GitHub-Event' => 'push', 'X-Hub-Signature-256' => '']],
])->throws(WebhookRejected::class);

it('accepts a GitLab push with the token and prefers checkout_sha', function () {
    $checkout = str_repeat('a', 40);
    $body = deployHookPush('refs/heads/main', DEPLOY_HOOK_COMMIT, ['checkout_sha' => $checkout]);
    $event = $this->webhooks->verify([
        'X-Gitlab-Event' => 'Push Hook',
        'X-Gitlab-Token' => DEPLOY_HOOK_SECRET,
    ], $body, DEPLOY_HOOK_SECRET);

    expect($event->provider)->toBe('gitlab')
        ->and($event->kind)->toBe('push')
        ->and($event->ref)->toBe('refs/heads/main')
        ->and($event->commit)->toBe($checkout);
});

it('falls back to after when GitLab sends no checkout_sha', function () {
    $body = deployHookPush('refs/heads/main', DEPLOY_HOOK_COMMIT, ['checkout_sha' => null]);
    $event = $this->webhooks->verify([
        'X-Gitlab-Event' => 'Push Hook',
        'X-Gitlab-Token' => DEPLOY_HOOK_SECRET,
    ], $body, DEPLOY_HOOK_SECRET);

    expect($event->commit)->toBe(DEPLOY_HOOK_COMMIT);
});

it('treats other GitLab events as other', function () {
    $event = $this->webhooks->verify([
        'X-Gitlab-Event' => 'Tag Push Hook',
        'X-Gitlab-Token' => DEPLOY_HOOK_SECRET,
    ], deployHookPush('refs/tags/v1'), DEPLOY_HOOK_SECRET);

    expect($event->kind)->toBe('other');
});

it('refuses a GitLab push with a bad or missing token', function (array $headers) {
    $this->webhooks->verify($headers, deployHookPush(), DEPLOY_HOOK_SECRET);
})->with([
    'wrong token' => [['X-Gitlab-Event' => 'Push Hook', 'X-Gitlab-Token' => 'not-the-secret']],
    'missing' => [['X-Gitlab-Event' => 'Push Hook']],
    'empty' => [['X-Gitlab-Event' => 'Push Hook', 'X-Gitlab-Token' => '']],
])->throws(WebhookRejected::class);

it('accepts a signed Gitea push', function () {
    $body = deployHookPush();
    $event = $this->webhooks->verify([
        'X-Gitea-Event' => 'push',
        'X-GitHub-Event' => 'push',
        'X-Gitea-Signature' => deployHookHmac($body),
    ], $body, DEPLOY_HOOK_SECRET);

    expect($event->provider)->toBe('gitea')
        ->and($event->kind)->toBe('push')
        ->and($event->ref)->toBe('refs/heads/main')
        ->and($event->commit)->toBe(DEPLOY_HOOK_COMMIT);
});

it('refuses a Gitea push with a bad or missing signature', function (array $headers) {
    $this->webhooks->verify($headers, deployHookPush(), DEPLOY_HOOK_SECRET);
})->with([
    'wrong secret' => [['X-Gitea-Event' => 'push', 'X-Gitea-Signature' => deployHookHmac(deployHookPush(), 'other')]],
    'github-style header only' => [['X-Gitea-Event' => 'push', 'X-Hub-Signature-256' => 'sha256='.deployHookHmac(deployHookPush())]],
    'missing' => [['X-Gitea-Event' => 'push']],
])->throws(WebhookRejected::class);

it('refuses a request from no known provider', function () {
    $this->webhooks->verify(['Content-Type' => 'application/json'], deployHookPush(), DEPLOY_HOOK_SECRET);
})->throws(WebhookRejected::class);

it('refuses everything when the domain has no secret', function () {
    $body = deployHookPush();
    $this->webhooks->verify([
        'X-GitHub-Event' => 'push',
        'X-Hub-Signature-256' => 'sha256='.deployHookHmac($body, ''),
    ], $body, '');
})->throws(WebhookRejected::class);

it('keeps a push to another branch as a valid push with its ref', function () {
    $body = deployHookPush('refs/heads/feature/login');
    $event = $this->webhooks->verify([
        'X-GitHub-Event' => 'push',
        'X-Hub-Signature-256' => 'sha256='.deployHookHmac($body),
    ], $body, DEPLOY_HOOK_SECRET);

    expect($event->kind)->toBe('push')
        ->and($event->ref)->toBe('refs/heads/feature/login')
        ->and($event->branch())->toBe('feature/login');
});

it('treats a branch deletion (zero commit) as other', function (array $headers, string $body) {
    $event = $this->webhooks->verify($headers, $body, DEPLOY_HOOK_SECRET);

    expect($event->kind)->toBe('other')
        ->and($event->commit)->toBeNull();
})->with([
    'github' => fn () => [
        ['X-GitHub-Event' => 'push', 'X-Hub-Signature-256' => 'sha256='.deployHookHmac(deployHookPush('refs/heads/main', str_repeat('0', 40)))],
        deployHookPush('refs/heads/main', str_repeat('0', 40)),
    ],
    'gitlab' => fn () => [
        ['X-Gitlab-Event' => 'Push Hook', 'X-Gitlab-Token' => DEPLOY_HOOK_SECRET],
        deployHookPush('refs/heads/main', str_repeat('0', 40), ['checkout_sha' => null]),
    ],
    'gitea' => fn () => [
        ['X-Gitea-Event' => 'push', 'X-Gitea-Signature' => deployHookHmac(deployHookPush('refs/heads/main', str_repeat('0', 40)))],
        deployHookPush('refs/heads/main', str_repeat('0', 40)),
    ],
]);

it('ignores a commit that is not a 40-hex sha', function () {
    $body = deployHookPush('refs/heads/main', 'not-a-sha');
    $event = $this->webhooks->verify([
        'X-GitHub-Event' => 'push',
        'X-Hub-Signature-256' => 'sha256='.deployHookHmac($body),
    ], $body, DEPLOY_HOOK_SECRET);

    expect($event->commit)->toBeNull();
});

it('reads a GitHub push sent as a form', function () {
    $body = 'payload='.rawurlencode(deployHookPush());
    $event = $this->webhooks->verify([
        'X-GitHub-Event' => 'push',
        'X-Hub-Signature-256' => 'sha256='.deployHookHmac($body),
    ], $body, DEPLOY_HOOK_SECRET);

    expect($event->kind)->toBe('push')
        ->and($event->commit)->toBe(DEPLOY_HOOK_COMMIT);
});

it('names the branch of a ref', function () {
    expect(WebhookSignature::branchOf('refs/heads/main'))->toBe('main')
        ->and(WebhookSignature::branchOf('refs/heads/release/2.7'))->toBe('release/2.7')
        ->and(WebhookSignature::branchOf('refs/heads/'))->toBeNull()
        ->and(WebhookSignature::branchOf('refs/tags/v1.0'))->toBeNull()
        ->and(WebhookSignature::branchOf('main'))->toBeNull()
        ->and(WebhookSignature::branchOf(null))->toBeNull();
});
