<?php

namespace App\Services\Deploy\Oidc;

use Closure;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Carbon;
use JsonException;
use Throwable;

/**
 * Verifies the OIDC token of a push-mode upload (E20 spec §6.3 steps 1–5,
 * docs/features/specs/2026-10-29-001-e20-git-deploy-design.md), in order:
 *
 * 1. decode the header and claims without trusting them; `iss` must be allowed;
 * 2. fetch the issuer's discovery document and JWKS (cached one hour, one
 *    refresh per call for an unknown `kid`) and verify RS256 or ES256;
 * 3. `aud` must contain the upload URL; `exp` in the future, `nbf` and `iat`
 *    not more than 60 seconds ahead;
 * 4. one trusted publisher must match;
 * 5. the `jti` must be new.
 *
 * Every refusal throws OidcRejected with the reason of its step. No JWT
 * library: the keys are rebuilt as PEM from the JWK and checked with ext-openssl.
 */
final class OidcVerifier
{
    public const GITHUB_ISSUER = 'https://token.actions.githubusercontent.com';

    private const CACHE_PREFIX = 'deploy.oidc.keys.';

    private const CACHE_SECONDS = 3600;

    private const HTTP_TIMEOUT = 10;

    private const CLOCK_SKEW = 60;

    private const MAX_TOKEN_BYTES = 16384;

    private readonly JtiStore $jtis;

    /**
     * @param  JtiStore|null  $jtis  used jti values, the cache by default
     * @param  (Closure(): int)|null  $clock  current Unix time, Carbon::now() by default
     */
    public function __construct(
        private readonly Factory $http,
        private readonly Repository $cache,
        ?JtiStore $jtis = null,
        private readonly ?Closure $clock = null,
    ) {
        $this->jtis = $jtis ?? new CacheJtiStore($cache, $clock);
    }

    /**
     * @param  string  $audience  the upload URL the token must be issued for
     * @param  list<string>  $allowedIssuers  DEPLOY_OIDC_ISSUERS
     * @param  list<array<string, string>>  $publishers  trusted publishers of the domain (PUB, ISSUER, SUBJECT_REPO, REF, ENVIRONMENT, WORKFLOW)
     * @param  bool  $checkReplay  false for the status of an upload, asked with the token of the upload itself
     *
     * @throws OidcRejected
     */
    public function verify(string $token, string $audience, array $allowedIssuers, array $publishers, bool $checkReplay = true): VerifiedToken
    {
        // 1. Decode without trusting anything.
        [$header, $claims, $signingInput, $signature] = $this->decode($token);
        $reject = fn (string $reason) => new OidcRejected($reason, $claims);

        $iss = is_string($claims['iss'] ?? null) ? $claims['iss'] : '';
        $issuer = self::normalizeIssuer($iss);
        $allowed = array_map(fn ($value) => self::normalizeIssuer((string) $value), $allowedIssuers);
        if ($issuer === '' || ! in_array($issuer, $allowed, true) || ! self::isHttps($issuer)) {
            throw $reject(OidcRejected::ISSUER);
        }

        // 2. The signature, with the issuer's published keys.
        $alg = $header['alg'] ?? null;
        if (! in_array($alg, ['RS256', 'ES256'], true) || array_key_exists('crit', $header)) {
            throw $reject(OidcRejected::SIGNATURE);
        }
        $kid = is_string($header['kid'] ?? null) ? $header['kid'] : null;
        if (! $this->signatureValid($issuer, $alg, $kid, $signingInput, $signature, $reject)) {
            throw $reject(OidcRejected::SIGNATURE);
        }

        // 3. Audience and time.
        $aud = $claims['aud'] ?? null;
        $audiences = is_array($aud) ? $aud : [$aud];
        if (! in_array($audience, $audiences, true)) {
            throw $reject(OidcRejected::AUDIENCE);
        }
        $now = $this->now();
        $exp = $claims['exp'] ?? null;
        if (! self::isTime($exp) || $exp <= $now) {
            throw $reject(OidcRejected::EXPIRED);
        }
        foreach (['nbf', 'iat'] as $name) {
            if (array_key_exists($name, $claims) && (! self::isTime($claims[$name]) || $claims[$name] > $now + self::CLOCK_SKEW)) {
                throw $reject(OidcRejected::EXPIRED);
            }
        }

        // 4. A trusted publisher.
        $publisher = null;
        foreach ($publishers as $candidate) {
            if (is_array($candidate) && $this->publisherMatches($candidate, $issuer, $claims)) {
                $publisher = (string) ($candidate['PUB'] ?? '');
                break;
            }
        }
        if ($publisher === null) {
            throw $reject(OidcRejected::PUBLISHER);
        }

        // 5. Replay.
        $jti = $claims['jti'] ?? null;
        if (! is_string($jti) || $jti === '' || ($checkReplay && ! $this->jtis->remember($jti, (int) $exp))) {
            throw $reject(OidcRejected::REPLAY);
        }

        return new VerifiedToken(
            claims: $claims,
            publisher: $publisher,
            issuer: $iss,
            subject: is_string($claims['sub'] ?? null) ? $claims['sub'] : '',
            exp: (int) $exp,
        );
    }

    /**
     * Whether one trusted publisher (a row of the CLI's list) matches the
     * claims: GitHub by repository, ref, environment and workflow path;
     * GitLab by project path, ref (as refs/heads/ or refs/tags/) and
     * environment. A publisher without REF and ENVIRONMENT never matches.
     *
     * @param  array<string, mixed>  $publisher
     * @param  array<string, mixed>  $claims
     */
    public function publisherMatches(array $publisher, string $issuer, array $claims): bool
    {
        $field = fn (string $key): string => is_scalar($publisher[$key] ?? null) ? (string) $publisher[$key] : '';
        $claim = fn (string $key): ?string => is_string($claims[$key] ?? null) ? $claims[$key] : null;

        $repo = $field('SUBJECT_REPO');
        $ref = $field('REF');
        $environment = $field('ENVIRONMENT');
        $workflow = $field('WORKFLOW');

        if (self::normalizeIssuer($field('ISSUER')) !== $issuer || $repo === '' || ($ref === '' && $environment === '')) {
            return false;
        }
        if ($environment !== '' && $claim('environment') !== $environment) {
            return false;
        }

        if ($issuer === self::GITHUB_ISSUER || $claim('repository') !== null) {
            $repository = $claim('repository');

            return $repository === $repo
                && ($ref === '' || $claim('ref') === $ref)
                && ($workflow === '' || self::workflowPath($claim('job_workflow_ref'), $repository) === $workflow);
        }

        if ($claim('project_path') !== null) {
            $tokenRef = $claim('ref');
            $fullRef = $tokenRef === null ? null
                : ($claim('ref_type') === 'tag' ? 'refs/tags/' : 'refs/heads/').$tokenRef;

            return $claim('project_path') === $repo && ($ref === '' || $fullRef === $ref);
        }

        return false;
    }

    /**
     * The path part of a GitHub `job_workflow_ref`
     * (`acme/shop/.github/workflows/deploy.yml@refs/heads/main` →
     * `.github/workflows/deploy.yml`); null when the workflow is not in the
     * token's own repository.
     */
    public static function workflowPath(?string $jobWorkflowRef, string $repository): ?string
    {
        $prefix = $repository.'/';
        if ($jobWorkflowRef === null || ! str_starts_with($jobWorkflowRef, $prefix)) {
            return null;
        }

        $rest = substr($jobWorkflowRef, strlen($prefix));
        $at = strpos($rest, '@');
        $path = $at === false ? $rest : substr($rest, 0, $at);

        return $path === '' ? null : $path;
    }

    public static function normalizeIssuer(string $issuer): string
    {
        return rtrim(trim($issuer), '/');
    }

    /**
     * @return array{0: array<string, mixed>, 1: array<string, mixed>, 2: string, 3: string}
     */
    private function decode(string $token): array
    {
        $parts = explode('.', $token);
        if (strlen($token) > self::MAX_TOKEN_BYTES || count($parts) !== 3) {
            throw new OidcRejected(OidcRejected::SIGNATURE);
        }

        try {
            $header = json_decode((string) self::base64UrlDecode($parts[0]), true, 16, JSON_THROW_ON_ERROR);
            $claims = json_decode((string) self::base64UrlDecode($parts[1]), true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new OidcRejected(OidcRejected::SIGNATURE);
        }
        $signature = self::base64UrlDecode($parts[2]);

        if (! is_array($header) || array_is_list($header) || ! is_array($claims) || ($claims !== [] && array_is_list($claims))) {
            throw new OidcRejected(OidcRejected::SIGNATURE);
        }
        if ($signature === null) {
            throw new OidcRejected(OidcRejected::SIGNATURE, $claims);
        }

        return [$header, $claims, $parts[0].'.'.$parts[1], $signature];
    }

    /**
     * @param  Closure(string): OidcRejected  $reject
     */
    private function signatureValid(string $issuer, string $alg, ?string $kid, string $input, string $signature, Closure $reject): bool
    {
        $cacheKey = self::CACHE_PREFIX.hash('sha256', $issuer);
        $keys = $this->cache->get($cacheKey);
        $fresh = false;
        if (! is_array($keys)) {
            $keys = $this->fetchKeys($issuer, $cacheKey, $reject);
            $fresh = true;
        }

        $candidates = $this->candidates($keys, $alg, $kid);
        if ($candidates === [] && ! $fresh) {
            // The issuer may have rotated its keys: refresh once.
            $candidates = $this->candidates($this->fetchKeys($issuer, $cacheKey, $reject), $alg, $kid);
        }

        foreach ($candidates as $jwk) {
            if ($this->verifyWith($jwk, $alg, $input, $signature)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Fetches the discovery document and the JWKS and caches the keys.
     *
     * @param  Closure(string): OidcRejected  $reject
     * @return list<array<string, mixed>>
     */
    private function fetchKeys(string $issuer, string $cacheKey, Closure $reject): array
    {
        $discovery = $this->fetchJson($issuer.'/.well-known/openid-configuration', $reject);

        $documentIssuer = is_string($discovery['issuer'] ?? null) ? self::normalizeIssuer($discovery['issuer']) : null;
        $jwksUri = $discovery['jwks_uri'] ?? null;
        if (($documentIssuer !== null && $documentIssuer !== $issuer) || ! is_string($jwksUri) || ! self::isHttps($jwksUri)) {
            throw $reject(OidcRejected::ISSUER);
        }

        $jwks = $this->fetchJson($jwksUri, $reject);
        $keys = array_values(array_filter(
            is_array($jwks['keys'] ?? null) ? $jwks['keys'] : [],
            fn ($key) => is_array($key),
        ));

        $this->cache->put($cacheKey, $keys, self::CACHE_SECONDS);

        return $keys;
    }

    /**
     * @param  Closure(string): OidcRejected  $reject
     * @return array<string, mixed>
     */
    private function fetchJson(string $url, Closure $reject): array
    {
        try {
            $response = $this->http
                ->timeout(self::HTTP_TIMEOUT)
                ->withOptions(['allow_redirects' => false])
                ->acceptJson()
                ->get($url);
            $data = $response->successful() ? $response->json() : null;
        } catch (Throwable) {
            $data = null;
        }

        if (! is_array($data)) {
            throw $reject(OidcRejected::SIGNATURE);
        }

        return $data;
    }

    /**
     * The keys of the set that may have signed the token.
     *
     * @param  list<array<string, mixed>>  $keys
     * @return list<array<string, mixed>>
     */
    private function candidates(array $keys, string $alg, ?string $kid): array
    {
        return array_values(array_filter($keys, function (array $jwk) use ($alg, $kid): bool {
            if ($kid !== null && ($jwk['kid'] ?? null) !== $kid) {
                return false;
            }
            if (isset($jwk['alg']) && $jwk['alg'] !== $alg) {
                return false;
            }
            if (isset($jwk['use']) && $jwk['use'] !== 'sig') {
                return false;
            }

            return $alg === 'RS256'
                ? ($jwk['kty'] ?? null) === 'RSA'
                : ($jwk['kty'] ?? null) === 'EC' && ($jwk['crv'] ?? null) === 'P-256';
        }));
    }

    /** @param  array<string, mixed>  $jwk */
    private function verifyWith(array $jwk, string $alg, string $input, string $signature): bool
    {
        if ($alg === 'ES256') {
            $pem = self::ecPem($jwk);
            $signature = self::ecSignatureToDer($signature);
        } else {
            $pem = self::rsaPem($jwk);
        }
        if ($pem === null || $signature === null) {
            return false;
        }

        $key = openssl_pkey_get_public($pem);
        $valid = $key !== false && openssl_verify($input, $signature, $key, OPENSSL_ALGO_SHA256) === 1;

        while (openssl_error_string() !== false) {
            // Drain the OpenSSL error queue so it does not leak into later calls.
        }

        return $valid;
    }

    /**
     * A SubjectPublicKeyInfo PEM from the `n` and `e` of an RSA JWK (2048 bits at least).
     *
     * @param  array<string, mixed>  $jwk
     */
    private static function rsaPem(array $jwk): ?string
    {
        $n = is_string($jwk['n'] ?? null) ? self::base64UrlDecode($jwk['n']) : null;
        $e = is_string($jwk['e'] ?? null) ? self::base64UrlDecode($jwk['e']) : null;
        if ($n === null || $e === null || strlen(ltrim($n, "\0")) < 256 || ltrim($e, "\0") === '') {
            return null;
        }

        $algorithm = self::der(0x30, "\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01\x05\x00"); // rsaEncryption, NULL
        $publicKey = self::der(0x30, self::derInteger($n).self::derInteger($e));

        return self::pem(self::der(0x30, $algorithm.self::der(0x03, "\0".$publicKey)));
    }

    /**
     * A SubjectPublicKeyInfo PEM from the `x` and `y` of a P-256 JWK.
     *
     * @param  array<string, mixed>  $jwk
     */
    private static function ecPem(array $jwk): ?string
    {
        $x = is_string($jwk['x'] ?? null) ? self::base64UrlDecode($jwk['x']) : null;
        $y = is_string($jwk['y'] ?? null) ? self::base64UrlDecode($jwk['y']) : null;
        if ($x === null || $y === null || strlen($x) > 32 || strlen($y) > 32) {
            return null;
        }

        $point = "\x04".str_pad($x, 32, "\0", STR_PAD_LEFT).str_pad($y, 32, "\0", STR_PAD_LEFT);
        // id-ecPublicKey, prime256v1
        $algorithm = self::der(0x30, "\x06\x07\x2a\x86\x48\xce\x3d\x02\x01\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x07");

        return self::pem(self::der(0x30, $algorithm.self::der(0x03, "\0".$point)));
    }

    /** The raw r||s of a JWS ES256 signature as the DER openssl_verify expects. */
    private static function ecSignatureToDer(string $raw): ?string
    {
        if (strlen($raw) !== 64) {
            return null;
        }

        return self::der(0x30, self::derInteger(substr($raw, 0, 32)).self::derInteger(substr($raw, 32)));
    }

    /** A positive DER INTEGER from big-endian bytes. */
    private static function derInteger(string $bytes): string
    {
        $bytes = ltrim($bytes, "\0");
        if ($bytes === '' || ord($bytes[0]) > 0x7F) {
            $bytes = "\0".$bytes;
        }

        return self::der(0x02, $bytes);
    }

    private static function der(int $tag, string $content): string
    {
        $length = strlen($content);
        $encoded = $length < 0x80 ? chr($length) : (
            $length < 0x100 ? "\x81".chr($length) : "\x82".pack('n', $length)
        );

        return chr($tag).$encoded.$content;
    }

    private static function pem(string $der): string
    {
        return "-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode($der), 64, "\n")."-----END PUBLIC KEY-----\n";
    }

    private static function base64UrlDecode(string $value): ?string
    {
        if (preg_match('/\A[A-Za-z0-9_-]*\z/', $value) !== 1) {
            return null;
        }
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);

        return $decoded === false ? null : $decoded;
    }

    private static function isHttps(string $url): bool
    {
        $parts = parse_url($url);

        return is_array($parts) && strtolower($parts['scheme'] ?? '') === 'https' && ($parts['host'] ?? '') !== '';
    }

    private static function isTime(mixed $value): bool
    {
        return is_int($value) || (is_float($value) && is_finite($value));
    }

    private function now(): int
    {
        return $this->clock !== null ? (int) ($this->clock)() : Carbon::now()->getTimestamp();
    }
}
