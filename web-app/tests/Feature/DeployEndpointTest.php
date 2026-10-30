<?php

use App\Arc\ExitCode;
use Illuminate\Support\Facades\Http;

/*
 * The Git Deploy endpoint behind the deploy port (E20,
 * docs/features/specs/2026-10-29-001-e20-git-deploy-design.md §6): webhooks
 * of pull mode, OIDC uploads of push mode and the status of an upload.
 */

const DEPLOY_EP_ID = 'k3v9q2m7x1c8';
const DEPLOY_EP_SECRET = 'webhook-secret-of-the-test-0123456789abcd';
const DEPLOY_EP_ISSUER = 'https://token.actions.githubusercontent.com';
const DEPLOY_EP_AUD = 'https://panel.example.com:8088/upload/k3v9q2m7x1c8';
const DEPLOY_EP_SHA = '3f2c9a1e4b5d6f708192a3b4c5d6e7f8091a2b3c';

function deployEpB64(string $bytes): string
{
    return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
}

/** @return array{private: OpenSSLAsymmetricKey, jwk: array<string, string>} */
function deployEpKey(): array
{
    static $key = null;
    if ($key === null) {
        $config = [];
        foreach ([getenv('OPENSSL_CONF') ?: null, dirname(PHP_BINARY).'/extras/ssl/openssl.cnf'] as $path) {
            if (is_string($path) && is_file($path)) {
                $config = ['config' => $path];
                break;
            }
        }
        $private = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048] + $config);
        $details = openssl_pkey_get_details($private);
        $key = ['private' => $private, 'jwk' => ['kty' => 'RSA', 'kid' => 'ep-1', 'alg' => 'RS256', 'use' => 'sig',
            'n' => deployEpB64($details['rsa']['n']), 'e' => deployEpB64($details['rsa']['e'])]];
    }

    return $key;
}

function deployEpToken(array $override = []): string
{
    $claims = array_merge([
        'iss' => DEPLOY_EP_ISSUER, 'aud' => DEPLOY_EP_AUD, 'sub' => 'repo:acme/shop:ref:refs/heads/main',
        'repository' => 'acme/shop', 'ref' => 'refs/heads/main', 'job_workflow_ref' => 'acme/shop/.github/workflows/deploy.yml@refs/heads/main',
        'exp' => time() + 300, 'iat' => time(), 'jti' => bin2hex(random_bytes(8)),
    ], $override);
    $input = deployEpB64(json_encode(['alg' => 'RS256', 'kid' => 'ep-1', 'typ' => 'JWT'])).'.'.deployEpB64(json_encode($claims));
    openssl_sign($input, $signature, deployEpKey()['private'], OPENSSL_ALGO_SHA256);

    return $input.'.'.deployEpB64($signature);
}

function deployEpGithub(array $payload, string $event = 'push', ?string $secret = DEPLOY_EP_SECRET): array
{
    $body = json_encode($payload);

    return [$body, array_filter([
        'HTTP_X_GITHUB_EVENT' => $event,
        'HTTP_X_HUB_SIGNATURE_256' => $secret === null ? null : 'sha256='.hash_hmac('sha256', $body, $secret),
        'CONTENT_TYPE' => 'application/json',
    ])];
}

beforeEach(function () {
    $this->fake = fakeArc();
    $this->fake->on('v-get-web-domain-deploy-endpoint')->returnsExit(ExitCode::NotExist, 'Error: no deploy with this id');
    $this->fake->on('v-get-web-domain-deploy-endpoint', [DEPLOY_EP_ID, 'hook'])->returnsJson([
        'USER' => 'bob', 'DOMAIN' => 'shop.example.com', 'MODE' => 'pull', 'BRANCH' => 'main', 'STATE' => 'active',
        'SECRET' => DEPLOY_EP_SECRET, 'PUBLISHERS' => [], 'ISSUERS' => [DEPLOY_EP_ISSUER], 'AUDIENCE' => '',
    ]);
    $this->fake->on('v-get-web-domain-deploy-endpoint', [DEPLOY_EP_ID, 'upload'])->returnsJson([
        'USER' => 'bob', 'DOMAIN' => 'shop.example.com', 'MODE' => 'push', 'BRANCH' => '', 'STATE' => 'active', 'SECRET' => '',
        'PUBLISHERS' => [['PUB' => 'p1', 'ISSUER' => DEPLOY_EP_ISSUER, 'SUBJECT_REPO' => 'acme/shop', 'REF' => 'refs/heads/main', 'ENVIRONMENT' => '', 'WORKFLOW' => '.github/workflows/deploy.yml']],
        'ISSUERS' => [DEPLOY_EP_ISSUER, 'https://gitlab.com'], 'AUDIENCE' => DEPLOY_EP_AUD,
    ]);
    $this->fake->on('v-schedule-web-domain-deploy')->returnsJson(['ID' => '17617400001234']);
    $this->fake->on('v-list-user-tasks', ['bob', '17617400001234', '20', 'json'])->returnsJson([
        'ID' => '17617400001234', 'KIND' => 'deploy', 'ARGS' => 'shop.example.com archive', 'STATUS' => 'running',
        'EXIT' => '', 'ERROR' => '', 'QUEUED' => '2026-10-29 14:25:01', 'START' => '2026-10-29 14:25:01', 'END' => '', 'LOG' => '',
    ]);
    Http::fake([
        DEPLOY_EP_ISSUER.'/.well-known/openid-configuration' => Http::response(['issuer' => DEPLOY_EP_ISSUER, 'jwks_uri' => DEPLOY_EP_ISSUER.'/.well-known/jwks']),
        DEPLOY_EP_ISSUER.'/.well-known/jwks' => Http::response(['keys' => [deployEpKey()['jwk']]]),
    ]);
});

// Webhooks (§6.2)

it('answers 404 for an unknown deploy id', function () {
    [$body, $server] = deployEpGithub(['ref' => 'refs/heads/main', 'after' => DEPLOY_EP_SHA]);

    $this->call('POST', '/_deploy/hooks/zzzzzzzzzzzz', [], [], [], $server, $body)->assertNotFound();
    $this->fake->assertNotRan('v-schedule-web-domain-deploy');
});

it('deploys the commit of a signed GitHub push to the branch', function () {
    [$body, $server] = deployEpGithub(['ref' => 'refs/heads/main', 'after' => DEPLOY_EP_SHA]);

    $this->call('POST', '/_deploy/hooks/'.DEPLOY_EP_ID, [], [], [], $server, $body)
        ->assertStatus(202)->assertExactJson(['task' => '17617400001234']);
    $this->fake->assertRan('v-schedule-web-domain-deploy', fn ($a) => $a === ['bob', 'shop.example.com', DEPLOY_EP_SHA, 'json']);
});

it('skips a push to another branch', function () {
    [$body, $server] = deployEpGithub(['ref' => 'refs/heads/feature', 'after' => DEPLOY_EP_SHA]);

    $this->call('POST', '/_deploy/hooks/'.DEPLOY_EP_ID, [], [], [], $server, $body)
        ->assertStatus(202)->assertExactJson(['skipped' => 'branch']);
    $this->fake->assertNotRan('v-schedule-web-domain-deploy');
});

it('answers a ping', function () {
    [$body, $server] = deployEpGithub(['zen' => 'Keep it simple.'], 'ping');

    $this->call('POST', '/_deploy/hooks/'.DEPLOY_EP_ID, [], [], [], $server, $body)->assertOk()->assertExactJson(['ok' => true]);
    $this->fake->assertNotRan('v-schedule-web-domain-deploy');
});

it('refuses a bad or missing signature with 401', function (?string $secret) {
    [$body, $server] = deployEpGithub(['ref' => 'refs/heads/main', 'after' => DEPLOY_EP_SHA], 'push', $secret);

    $this->call('POST', '/_deploy/hooks/'.DEPLOY_EP_ID, [], [], [], $server, $body)
        ->assertStatus(401)->assertExactJson(['error' => 'signature']);
    $this->fake->assertNotRan('v-schedule-web-domain-deploy');
})->with(['wrong secret' => ['not-the-secret'], 'no signature' => [null]]);

it('accepts GitLab and Gitea pushes', function () {
    $body = json_encode(['ref' => 'refs/heads/main', 'checkout_sha' => DEPLOY_EP_SHA, 'after' => DEPLOY_EP_SHA]);
    $this->call('POST', '/_deploy/hooks/'.DEPLOY_EP_ID, [], [], [], ['HTTP_X_GITLAB_EVENT' => 'Push Hook', 'HTTP_X_GITLAB_TOKEN' => DEPLOY_EP_SECRET], $body)
        ->assertStatus(202);
    $this->call('POST', '/_deploy/hooks/'.DEPLOY_EP_ID, [], [], [], ['HTTP_X_GITEA_EVENT' => 'push', 'HTTP_X_GITEA_SIGNATURE' => hash_hmac('sha256', $body, DEPLOY_EP_SECRET)], $body)
        ->assertStatus(202);
    $this->fake->assertRan('v-schedule-web-domain-deploy', null, 2);
});

it('answers the deploy that could not start with 409', function () {
    $this->fake->on('v-schedule-web-domain-deploy')->returnsExit(ExitCode::Suspended, 'Error: shop.example.com is suspended');
    [$body, $server] = deployEpGithub(['ref' => 'refs/heads/main', 'after' => DEPLOY_EP_SHA]);

    $this->call('POST', '/_deploy/hooks/'.DEPLOY_EP_ID, [], [], [], $server, $body)
        ->assertStatus(409)->assertExactJson(['error' => 'not started']);
});

// Uploads (§6.3)

it('hands a verified upload to the schedule command and removes its copy', function () {
    $seen = null;
    $this->fake->on('v-schedule-web-domain-deploy')->using(function (array $args) use (&$seen) {
        $seen = $args;
        expect(is_file($args[3]))->toBeTrue()->and(file_get_contents($args[3]))->toBe('archive-bytes');

        return [0, json_encode(['ID' => '17617400001234'])];
    });

    $this->call('POST', '/_deploy/upload/'.DEPLOY_EP_ID, [], [], [], ['HTTP_AUTHORIZATION' => 'Bearer '.deployEpToken(), 'CONTENT_TYPE' => 'application/gzip'], 'archive-bytes')
        ->assertStatus(202)->assertJson(['task' => '17617400001234'])
        ->assertHeader('Location', 'https://panel.example.com:8088/status/'.DEPLOY_EP_ID.'/17617400001234');
    expect($seen[0])->toBe('bob')->and($seen[1])->toBe('shop.example.com')->and($seen[2])->toBe('--artifact')
        ->and(file_exists($seen[3]))->toBeFalse();
});

it('refuses a token with the reason of its step', function (array $override, string $reason) {
    $this->call('POST', '/_deploy/upload/'.DEPLOY_EP_ID, [], [], [], ['HTTP_AUTHORIZATION' => 'Bearer '.deployEpToken($override)], 'x')
        ->assertStatus(401)->assertExactJson(['error' => $reason]);
    $this->fake->assertNotRan('v-schedule-web-domain-deploy');
})->with([
    'issuer' => [['iss' => 'https://evil.example.com'], 'issuer'],
    'audience' => [['aud' => 'https://other.example.com:8088/upload/k3v9q2m7x1c8'], 'audience'],
    'expired' => [['exp' => time() - 10], 'expired'],
    'publisher' => [['repository' => 'acme/blog'], 'publisher'],
]);

it('refuses an upload without a token and a replayed token', function () {
    $this->call('POST', '/_deploy/upload/'.DEPLOY_EP_ID, [], [], [], [], 'x')->assertStatus(401)->assertExactJson(['error' => 'signature']);
    $token = deployEpToken();
    $this->call('POST', '/_deploy/upload/'.DEPLOY_EP_ID, [], [], [], ['HTTP_AUTHORIZATION' => 'Bearer '.$token], 'x')->assertStatus(202);
    $this->call('POST', '/_deploy/upload/'.DEPLOY_EP_ID, [], [], [], ['HTTP_AUTHORIZATION' => 'Bearer '.$token], 'x')
        ->assertStatus(401)->assertExactJson(['error' => 'replay']);
});

it('answers 404 for an upload to a pull-mode or unknown id', function () {
    $this->call('POST', '/_deploy/upload/zzzzzzzzzzzz', [], [], [], ['HTTP_AUTHORIZATION' => 'Bearer '.deployEpToken()], 'x')->assertNotFound();
});

// Status (§6.3 step 6)

it('answers the task state to the publisher of the upload', function () {
    $token = deployEpToken();
    $this->call('POST', '/_deploy/upload/'.DEPLOY_EP_ID, [], [], [], ['HTTP_AUTHORIZATION' => 'Bearer '.$token], 'x')->assertStatus(202);

    $this->call('GET', '/_deploy/status/'.DEPLOY_EP_ID.'/17617400001234', [], [], [], ['HTTP_AUTHORIZATION' => 'Bearer '.$token])
        ->assertOk()->assertJson(['task' => '17617400001234', 'status' => 'running']);
    // Another task, or a token of another run of the publisher that did not upload it
    $this->call('GET', '/_deploy/status/'.DEPLOY_EP_ID.'/17617400009999', [], [], [], ['HTTP_AUTHORIZATION' => 'Bearer '.$token])
        ->assertNotFound();
    $this->call('GET', '/_deploy/status/'.DEPLOY_EP_ID.'/17617400001234', [], [], [], [])->assertStatus(401);
});

it('sets no session cookie', function () {
    [$body, $server] = deployEpGithub(['zen' => 'x'], 'ping');

    $response = $this->call('POST', '/_deploy/hooks/'.DEPLOY_EP_ID, [], [], [], $server, $body);
    expect($response->headers->getCookies())->toBe([]);
});
