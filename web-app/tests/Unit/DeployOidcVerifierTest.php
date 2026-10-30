<?php

use App\Services\Deploy\Oidc\CacheJtiStore;
use App\Services\Deploy\Oidc\JtiStore;
use App\Services\Deploy\Oidc\OidcRejected;
use App\Services\Deploy\Oidc\OidcVerifier;
use App\Services\Deploy\Oidc\VerifiedToken;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

// The OIDC token check of push-mode deploy (E20 spec §6.3, steps 1–5).

const DEPLOY_OIDC_NOW = 1_900_000_000;
const DEPLOY_OIDC_AUD = 'https://panel.example.com:8088/upload/k3v9q2m7x1c8';
const DEPLOY_OIDC_GITHUB = 'https://token.actions.githubusercontent.com';
const DEPLOY_OIDC_GITLAB = 'https://gitlab.com';

/**
 * Key generation needs an openssl.cnf; the Windows PHP builds ship one in
 * extras/ssl but do not point OpenSSL at it, so pass it explicitly there.
 *
 * @return array{config?: string}
 */
function deployOidcSslConfig(): array
{
    static $config = null;
    if ($config === null) {
        $config = [];
        foreach ([getenv('OPENSSL_CONF') ?: null, dirname(PHP_BINARY).'/extras/ssl/openssl.cnf'] as $path) {
            if (is_string($path) && is_file($path)) {
                $config = ['config' => $path];
                break;
            }
        }
    }

    return $config;
}

function deployOidcB64(string $bytes): string
{
    return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
}

/**
 * A key pair of the test, generated once per kid: RSA 2048 or EC P-256.
 *
 * @return array{private: OpenSSLAsymmetricKey, jwk: array<string, string>}
 */
function deployOidcKey(string $kid, string $type = 'RSA'): array
{
    static $keys = [];
    if (isset($keys[$kid])) {
        return $keys[$kid];
    }

    $options = $type === 'EC'
        ? ['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']
        : ['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048];
    $private = openssl_pkey_new($options + deployOidcSslConfig());
    expect($private)->not->toBeFalse('openssl_pkey_new failed: '.openssl_error_string());
    $details = openssl_pkey_get_details($private);

    $jwk = $type === 'EC'
        ? [
            'kty' => 'EC', 'crv' => 'P-256', 'kid' => $kid, 'use' => 'sig', 'alg' => 'ES256',
            'x' => deployOidcB64(str_pad($details['ec']['x'], 32, "\0", STR_PAD_LEFT)),
            'y' => deployOidcB64(str_pad($details['ec']['y'], 32, "\0", STR_PAD_LEFT)),
        ]
        : [
            'kty' => 'RSA', 'kid' => $kid, 'use' => 'sig', 'alg' => 'RS256',
            'n' => deployOidcB64($details['rsa']['n']), 'e' => deployOidcB64($details['rsa']['e']),
        ];

    return $keys[$kid] = ['private' => $private, 'jwk' => $jwk];
}

/** An ECDSA DER signature as the raw r||s of a JWS. */
function deployOidcRawEcSignature(string $der): string
{
    $offset = 2;
    $parts = [];
    for ($i = 0; $i < 2; $i++) {
        $length = ord($der[$offset + 1]);
        $parts[] = str_pad(ltrim(substr($der, $offset + 2, $length), "\0"), 32, "\0", STR_PAD_LEFT);
        $offset += 2 + $length;
    }

    return $parts[0].$parts[1];
}

/** @param  array<string, mixed>  $claims */
function deployOidcToken(array $claims, string $kid = 'rsa-1', string $alg = 'RS256', array $header = []): string
{
    $header = $header + ['alg' => $alg, 'typ' => 'JWT', 'kid' => $kid];
    $input = deployOidcB64((string) json_encode($header)).'.'.deployOidcB64((string) json_encode($claims));

    $signature = match ($alg) {
        'RS256' => (function () use ($input, $kid) {
            openssl_sign($input, $signature, deployOidcKey($kid)['private'], OPENSSL_ALGO_SHA256);

            return $signature;
        })(),
        'ES256' => (function () use ($input, $kid) {
            openssl_sign($input, $der, deployOidcKey($kid, 'EC')['private'], OPENSSL_ALGO_SHA256);

            return deployOidcRawEcSignature($der);
        })(),
        'HS256' => hash_hmac('sha256', $input, 'guessable', true),
        default => '',
    };

    return $input.'.'.deployOidcB64($signature);
}

/**
 * @param  array<string, mixed>  $override  a null value removes the claim
 * @return array<string, mixed>
 */
function deployOidcClaims(array $base, array $override): array
{
    return array_filter(array_merge($base, $override), fn ($value) => $value !== null);
}

function deployOidcGithubClaims(array $override = []): array
{
    return deployOidcClaims([
        'iss' => DEPLOY_OIDC_GITHUB,
        'aud' => DEPLOY_OIDC_AUD,
        'sub' => 'repo:acme/shop:environment:production',
        'repository' => 'acme/shop',
        'ref' => 'refs/heads/main',
        'environment' => 'production',
        'job_workflow_ref' => 'acme/shop/.github/workflows/deploy.yml@refs/heads/main',
        'jti' => bin2hex(random_bytes(8)),
        'iat' => DEPLOY_OIDC_NOW - 10,
        'nbf' => DEPLOY_OIDC_NOW - 10,
        'exp' => DEPLOY_OIDC_NOW + 300,
    ], $override);
}

function deployOidcGitlabClaims(array $override = []): array
{
    return deployOidcClaims([
        'iss' => DEPLOY_OIDC_GITLAB,
        'aud' => DEPLOY_OIDC_AUD,
        'sub' => 'project_path:acme/shop:ref_type:branch:ref:main',
        'project_path' => 'acme/shop',
        'ref' => 'main',
        'ref_type' => 'branch',
        'environment' => 'production',
        'jti' => bin2hex(random_bytes(8)),
        'iat' => DEPLOY_OIDC_NOW - 10,
        'exp' => DEPLOY_OIDC_NOW + 300,
    ], $override);
}

/** @return array<string, string> */
function deployOidcPublisher(array $override = []): array
{
    return array_merge([
        'PUB' => 'gh1',
        'ISSUER' => DEPLOY_OIDC_GITHUB,
        'SUBJECT_REPO' => 'acme/shop',
        'REF' => 'refs/heads/main',
        'ENVIRONMENT' => 'production',
        'WORKFLOW' => '.github/workflows/deploy.yml',
    ], $override);
}

function deployOidcReason(Closure $call): ?string
{
    try {
        $call();
    } catch (OidcRejected $e) {
        return $e->reason;
    }

    return null;
}

beforeEach(function () {
    $this->jwks = [
        DEPLOY_OIDC_GITHUB => [deployOidcKey('rsa-1')['jwk'], deployOidcKey('ec-1', 'EC')['jwk']],
        DEPLOY_OIDC_GITLAB => [deployOidcKey('rsa-gl')['jwk']],
    ];
    $this->jwksUri = [
        DEPLOY_OIDC_GITHUB => DEPLOY_OIDC_GITHUB.'/.well-known/jwks',
        DEPLOY_OIDC_GITLAB => DEPLOY_OIDC_GITLAB.'/oauth/discovery/keys',
    ];

    $this->down = false;
    $this->discoveryIssuer = [];

    Http::fake(function (Request $request) {
        if ($this->down) {
            return Http::response('down', 503);
        }
        foreach ($this->jwksUri as $issuer => $jwksUri) {
            if ($request->url() === $issuer.'/.well-known/openid-configuration') {
                return Http::response(['issuer' => $this->discoveryIssuer[$issuer] ?? $issuer, 'jwks_uri' => $jwksUri]);
            }
            if ($request->url() === $jwksUri) {
                return Http::response(['keys' => $this->jwks[$issuer]]);
            }
        }

        return Http::response('', 404);
    });

    $this->cache = new Repository(new ArrayStore);
    $this->verifier = new OidcVerifier(app(Factory::class), $this->cache, clock: fn () => DEPLOY_OIDC_NOW);
    $this->issuers = [DEPLOY_OIDC_GITHUB, DEPLOY_OIDC_GITLAB.'/'];
    $this->publishers = [
        deployOidcPublisher(),
        deployOidcPublisher(['PUB' => 'gl1', 'ISSUER' => DEPLOY_OIDC_GITLAB, 'ENVIRONMENT' => '', 'WORKFLOW' => '']),
    ];

    $this->verify = fn (string $token, ?array $publishers = null) => $this->verifier->verify(
        $token, DEPLOY_OIDC_AUD, $this->issuers, $publishers ?? $this->publishers,
    );
    $this->reason = fn (string $token, ?array $publishers = null) => deployOidcReason(
        fn () => ($this->verify)($token, $publishers),
    );
    $this->fetches = fn (string $suffix) => collect(Http::recorded())
        ->filter(fn (array $pair) => str_ends_with($pair[0]->url(), $suffix))
        ->count();
});

it('accepts a GitHub token signed with RS256', function () {
    $claims = deployOidcGithubClaims();
    $verified = ($this->verify)(deployOidcToken($claims));

    expect($verified)->toBeInstanceOf(VerifiedToken::class)
        ->and($verified->publisher)->toBe('gh1')
        ->and($verified->issuer)->toBe(DEPLOY_OIDC_GITHUB)
        ->and($verified->subject)->toBe('repo:acme/shop:environment:production')
        ->and($verified->exp)->toBe(DEPLOY_OIDC_NOW + 300)
        ->and($verified->claims)->toBe($claims);
});

it('accepts a token signed with ES256', function () {
    $verified = ($this->verify)(deployOidcToken(deployOidcGithubClaims(), 'ec-1', 'ES256'));

    expect($verified->publisher)->toBe('gh1');
});

it('accepts a GitLab token and reads its bare ref as a branch', function () {
    $verified = ($this->verify)(deployOidcToken(deployOidcGitlabClaims(['iss' => DEPLOY_OIDC_GITLAB.'/']), 'rsa-gl'));

    expect($verified->publisher)->toBe('gl1');
});

it('reads a GitLab tag ref as refs/tags', function () {
    $token = deployOidcToken(deployOidcGitlabClaims(['ref' => 'v1.2', 'ref_type' => 'tag']), 'rsa-gl');
    $tags = [deployOidcPublisher(['PUB' => 'gl-tag', 'ISSUER' => DEPLOY_OIDC_GITLAB, 'REF' => 'refs/tags/v1.2'])];
    $heads = [deployOidcPublisher(['PUB' => 'gl-head', 'ISSUER' => DEPLOY_OIDC_GITLAB, 'REF' => 'refs/heads/v1.2'])];

    expect(($this->reason)($token, $heads))->toBe('publisher')
        ->and(($this->verify)($token, $tags)->publisher)->toBe('gl-tag');
});

it('ignores the workflow of a GitLab publisher', function () {
    $publishers = [deployOidcPublisher(['PUB' => 'gl1', 'ISSUER' => DEPLOY_OIDC_GITLAB, 'WORKFLOW' => 'anything.yml'])];

    expect(($this->verify)(deployOidcToken(deployOidcGitlabClaims(), 'rsa-gl'), $publishers)->publisher)->toBe('gl1');
});

it('matches the first publisher that fits and allows empty optional fields', function () {
    $publishers = [
        deployOidcPublisher(['PUB' => 'other-repo', 'SUBJECT_REPO' => 'acme/blog']),
        deployOidcPublisher(['PUB' => 'by-env', 'REF' => '', 'WORKFLOW' => '']),
        deployOidcPublisher(['PUB' => 'later']),
    ];

    expect(($this->verify)(deployOidcToken(deployOidcGithubClaims()), $publishers)->publisher)->toBe('by-env');
});

it('refuses an issuer that is not allowed', function () {
    $token = deployOidcToken(deployOidcGithubClaims(['iss' => 'https://evil.example.com']));

    expect(($this->reason)($token))->toBe('issuer')
        ->and(Http::recorded())->toHaveCount(0);
});

it('refuses a token without an issuer', function () {
    expect(($this->reason)(deployOidcToken(deployOidcGithubClaims(['iss' => null]))))->toBe('issuer');
});

it('refuses an issuer or a key set that is not https', function () {
    $this->issuers[] = 'http://insecure.example.com';
    $plain = deployOidcToken(deployOidcGithubClaims(['iss' => 'http://insecure.example.com']));
    expect(($this->reason)($plain))->toBe('issuer');

    $this->jwksUri[DEPLOY_OIDC_GITHUB] = 'http://token.actions.githubusercontent.com/.well-known/jwks';
    expect(($this->reason)(deployOidcToken(deployOidcGithubClaims())))->toBe('issuer');
});

it('refuses a discovery document of another issuer', function () {
    $this->discoveryIssuer[DEPLOY_OIDC_GITHUB] = 'https://evil.example.com';

    expect(($this->reason)(deployOidcToken(deployOidcGithubClaims())))->toBe('issuer');
});

it('refuses a bad signature', function () {
    // Signed with a key that has the kid of the published one.
    $good = deployOidcToken(deployOidcGithubClaims());
    $other = deployOidcToken(deployOidcGithubClaims(), 'rsa-other');
    [$header, $claims] = explode('.', $good);
    $forged = $header.'.'.$claims.'.'.explode('.', $other)[2];

    $tampered = explode('.', $good);
    $tampered[1] = deployOidcB64((string) json_encode(deployOidcGithubClaims(['repository' => 'acme/evil'])));

    expect(($this->reason)($forged))->toBe('signature')
        ->and(($this->reason)(implode('.', $tampered)))->toBe('signature');
});

it('refuses an ES256 signature that is not 64 raw bytes', function () {
    $token = deployOidcToken(deployOidcGithubClaims(), 'ec-1', 'ES256');
    $parts = explode('.', $token);
    $parts[2] = deployOidcB64(substr(base64_decode(strtr($parts[2], '-_', '+/')), 0, 63));

    expect(($this->reason)(implode('.', $parts)))->toBe('signature');
});

it('refuses alg none and HS256', function (string $alg) {
    expect(($this->reason)(deployOidcToken(deployOidcGithubClaims(), 'rsa-1', $alg)))->toBe('signature');
})->with(['none', 'HS256', 'RS512']);

it('refuses a key of the wrong type for the algorithm', function () {
    // An RS256 token pointing at the EC key.
    expect(($this->reason)(deployOidcToken(deployOidcGithubClaims(), 'rsa-1', 'RS256', ['kid' => 'ec-1'])))->toBe('signature');
});

it('refuses a malformed token', function (string $token) {
    expect(($this->reason)($token))->toBe('signature');
})->with([
    'empty' => '',
    'two parts' => 'abc.def',
    'not json' => deployOidcB64('x').'.'.deployOidcB64('y').'.'.deployOidcB64('z'),
]);

it('refuses when the issuer cannot be reached', function () {
    $this->down = true;

    expect(($this->reason)(deployOidcToken(deployOidcGithubClaims())))->toBe('signature');
});

it('refuses a wrong audience', function (mixed $aud) {
    expect(($this->reason)(deployOidcToken(deployOidcGithubClaims(['aud' => $aud]))))->toBe('audience');
})->with([
    'other upload url' => 'https://panel.example.com:8088/upload/other',
    'prefix' => 'https://panel.example.com:8088/upload/',
    'list without it' => [['https://a.example.com', 'https://b.example.com']],
    'missing' => null,
]);

it('accepts an audience list that contains the upload url', function () {
    $token = deployOidcToken(deployOidcGithubClaims(['aud' => ['https://other.example.com', DEPLOY_OIDC_AUD]]));

    expect(($this->verify)($token)->publisher)->toBe('gh1');
});

it('refuses an expired token', function (array $override) {
    expect(($this->reason)(deployOidcToken(deployOidcGithubClaims($override))))->toBe('expired');
})->with([
    'expired' => [['exp' => DEPLOY_OIDC_NOW - 1]],
    'expires now' => [['exp' => DEPLOY_OIDC_NOW]],
    'no exp' => [['exp' => null]],
    'nbf beyond 60 s' => [['nbf' => DEPLOY_OIDC_NOW + 61]],
    'iat beyond 60 s' => [['iat' => DEPLOY_OIDC_NOW + 61]],
    'nbf not a number' => [['nbf' => 'soon']],
]);

it('allows nbf and iat up to 60 seconds ahead', function () {
    $token = deployOidcToken(deployOidcGithubClaims(['nbf' => DEPLOY_OIDC_NOW + 60, 'iat' => DEPLOY_OIDC_NOW + 60]));

    expect(($this->verify)($token)->publisher)->toBe('gh1');
});

it('uses the current time when no clock is given', function () {
    $verifier = new OidcVerifier(app(Factory::class), new Repository(new ArrayStore));
    $token = deployOidcToken(deployOidcGithubClaims());

    Carbon::setTestNow(Carbon::createFromTimestamp(DEPLOY_OIDC_NOW + 400));
    expect(deployOidcReason(fn () => $verifier->verify($token, DEPLOY_OIDC_AUD, $this->issuers, $this->publishers)))->toBe('expired');

    Carbon::setTestNow(Carbon::createFromTimestamp(DEPLOY_OIDC_NOW));
    expect($verifier->verify($token, DEPLOY_OIDC_AUD, $this->issuers, $this->publishers)->publisher)->toBe('gh1');

    Carbon::setTestNow();
});

it('refuses a token that matches no publisher', function (array $claims) {
    expect(($this->reason)(deployOidcToken(deployOidcGithubClaims($claims))))->toBe('publisher');
})->with([
    'repository' => [['repository' => 'acme/blog']],
    'ref' => [['ref' => 'refs/heads/dev']],
    'environment' => [['environment' => 'staging']],
    'no environment' => [['environment' => null]],
    'workflow' => [['job_workflow_ref' => 'acme/shop/.github/workflows/other.yml@refs/heads/main']],
    'reusable workflow of another repository' => [['job_workflow_ref' => 'acme/tools/.github/workflows/deploy.yml@refs/heads/main']],
]);

it('refuses a GitLab token that matches no publisher', function (array $claims) {
    expect(($this->reason)(deployOidcToken(deployOidcGitlabClaims($claims), 'rsa-gl')))->toBe('publisher');
})->with([
    'project' => [['project_path' => 'acme/blog']],
    'ref' => [['ref' => 'dev']],
]);

it('refuses a publisher of another issuer', function () {
    $publishers = [deployOidcPublisher(['ISSUER' => DEPLOY_OIDC_GITLAB])];

    expect(($this->reason)(deployOidcToken(deployOidcGithubClaims()), $publishers))->toBe('publisher');
});

it('never matches a publisher with neither ref nor environment', function () {
    $publishers = [deployOidcPublisher(['REF' => '', 'ENVIRONMENT' => '', 'WORKFLOW' => ''])];

    expect(($this->reason)(deployOidcToken(deployOidcGithubClaims()), $publishers))->toBe('publisher')
        ->and(($this->reason)(deployOidcToken(deployOidcGithubClaims()), []))->toBe('publisher');
});

it('refuses a replayed jti and a token without one', function () {
    $token = deployOidcToken(deployOidcGithubClaims(['jti' => 'run-42']));

    expect(($this->verify)($token)->publisher)->toBe('gh1')
        ->and(($this->reason)($token))->toBe('replay')
        ->and(($this->reason)(deployOidcToken(deployOidcGithubClaims(['jti' => null]))))->toBe('replay')
        ->and(($this->reason)(deployOidcToken(deployOidcGithubClaims(['jti' => '']))))->toBe('replay');
});

it('checks a used token again without the replay check, for the status of its upload', function () {
    $token = deployOidcToken(deployOidcGithubClaims(['jti' => 'run-44']));

    expect(($this->verify)($token)->publisher)->toBe('gh1')
        ->and($this->verifier->verify($token, DEPLOY_OIDC_AUD, $this->issuers, $this->publishers, checkReplay: false)->publisher)->toBe('gh1')
        ->and(($this->reason)($token))->toBe('replay');
});

it('does not use up the jti of a refused token', function () {
    $claims = deployOidcGithubClaims(['jti' => 'run-43']);

    expect(($this->reason)(deployOidcToken($claims), [deployOidcPublisher(['SUBJECT_REPO' => 'acme/blog'])]))->toBe('publisher')
        ->and(($this->verify)(deployOidcToken($claims))->publisher)->toBe('gh1');
});

it('keeps a jti until the token expires', function () {
    $store = new CacheJtiStore(new Repository(new ArrayStore), fn () => DEPLOY_OIDC_NOW);

    expect($store)->toBeInstanceOf(JtiStore::class)
        ->and($store->remember('a', DEPLOY_OIDC_NOW + 300))->toBeTrue()
        ->and($store->remember('a', DEPLOY_OIDC_NOW + 300))->toBeFalse()
        ->and($store->remember('b', DEPLOY_OIDC_NOW - 5))->toBeTrue();
});

it('uses an injected jti store', function () {
    $store = new class implements JtiStore
    {
        public array $seen = [];

        public function remember(string $jti, int $exp): bool
        {
            $this->seen[] = [$jti, $exp];

            return false;
        }
    };
    $verifier = new OidcVerifier(app(Factory::class), $this->cache, $store, fn () => DEPLOY_OIDC_NOW);

    expect(deployOidcReason(fn () => $verifier->verify(
        deployOidcToken(deployOidcGithubClaims(['jti' => 'run-44'])), DEPLOY_OIDC_AUD, $this->issuers, $this->publishers,
    )))->toBe('replay')
        ->and($store->seen)->toBe([['run-44', DEPLOY_OIDC_NOW + 300]]);
});

it('caches the key set and refreshes it once for an unknown kid', function () {
    ($this->verify)(deployOidcToken(deployOidcGithubClaims()));
    ($this->verify)(deployOidcToken(deployOidcGithubClaims()));
    expect(($this->fetches)('/openid-configuration'))->toBe(1)
        ->and(($this->fetches)('/jwks'))->toBe(1);

    // The issuer rotates its key: the cached set lacks rsa-2, one refresh finds it.
    $this->jwks[DEPLOY_OIDC_GITHUB] = [deployOidcKey('rsa-1')['jwk'], deployOidcKey('rsa-2')['jwk']];
    expect(($this->verify)(deployOidcToken(deployOidcGithubClaims(), 'rsa-2'))->publisher)->toBe('gh1')
        ->and(($this->fetches)('/jwks'))->toBe(2);

    // A kid the issuer does not publish: one refresh in this call, no more.
    expect(($this->reason)(deployOidcToken(deployOidcGithubClaims(), 'rsa-other')))->toBe('signature')
        ->and(($this->fetches)('/jwks'))->toBe(3);

    // The refreshed set is cached: rsa-2 needs no further fetch.
    ($this->verify)(deployOidcToken(deployOidcGithubClaims(), 'rsa-2'));
    expect(($this->fetches)('/jwks'))->toBe(3);
});

it('fetches the key set again after an hour', function () {
    ($this->verify)(deployOidcToken(deployOidcGithubClaims()));
    $this->travel(3601)->seconds();
    ($this->verify)(deployOidcToken(deployOidcGithubClaims()));

    expect(($this->fetches)('/jwks'))->toBe(2);
});

it('is built by the container', function () {
    expect(app(OidcVerifier::class))->toBeInstanceOf(OidcVerifier::class);
});

it('names its reason in the exception', function () {
    $e = new OidcRejected('audience');

    expect($e->reason)->toBe('audience')
        ->and($e->getMessage())->toContain('audience')
        ->and(fn () => new OidcRejected('bogus'))->toThrow(InvalidArgumentException::class);
});
