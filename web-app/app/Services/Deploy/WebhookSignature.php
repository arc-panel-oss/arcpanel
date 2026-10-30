<?php

namespace App\Services\Deploy;

/**
 * Verifies a webhook request of pull-mode deploy and reads its event
 * (E20 spec §6.2, docs/features/specs/2026-10-29-001-e20-git-deploy-design.md).
 *
 * | Provider | Signature header      | Check                                  |
 * | -------- | --------------------- | -------------------------------------- |
 * | GitHub   | `X-Hub-Signature-256` | `sha256=` + hex HMAC-SHA256 of the body |
 * | GitLab   | `X-Gitlab-Token`      | constant-time comparison with secret   |
 * | Gitea    | `X-Gitea-Signature`   | hex HMAC-SHA256 of the body            |
 *
 * Gitea also sends the GitHub headers, so it is recognised first.
 */
final class WebhookSignature
{
    private const ZERO_COMMIT = '0000000000000000000000000000000000000000';

    /**
     * @param  array<string, string|list<string>|null>  $headers  request headers, names in any case
     *
     * @throws WebhookRejected when the provider is unknown or the signature is missing or wrong
     */
    public function verify(array $headers, string $body, string $secret): WebhookEvent
    {
        if ($secret === '') {
            throw new WebhookRejected('The domain has no webhook secret.');
        }

        $header = $this->headerReader($headers);

        if ($header('x-gitea-event') !== null || $header('x-gitea-signature') !== null) {
            $this->checkHmac($header('x-gitea-signature'), '', $body, $secret);

            return $this->event('gitea', $header('x-gitea-event') === 'push', $body, ['after']);
        }

        if ($header('x-gitlab-event') !== null || $header('x-gitlab-token') !== null) {
            $token = $header('x-gitlab-token');
            if ($token === null || $token === '' || ! hash_equals($secret, $token)) {
                throw new WebhookRejected('Missing or wrong GitLab token.');
            }

            return $this->event('gitlab', $header('x-gitlab-event') === 'Push Hook', $body, ['checkout_sha', 'after']);
        }

        if ($header('x-github-event') !== null || $header('x-hub-signature-256') !== null) {
            $this->checkHmac($header('x-hub-signature-256'), 'sha256=', $body, $secret);

            if ($header('x-github-event') === 'ping') {
                return new WebhookEvent('github', WebhookEvent::KIND_PING, null, null);
            }

            return $this->event('github', $header('x-github-event') === 'push', $body, ['after']);
        }

        throw new WebhookRejected('No known webhook provider.');
    }

    /** `main` for `refs/heads/main`; null for a tag, a bare name or no ref. */
    public static function branchOf(?string $ref): ?string
    {
        if ($ref === null || ! str_starts_with($ref, 'refs/heads/')) {
            return null;
        }

        $branch = substr($ref, strlen('refs/heads/'));

        return $branch === '' ? null : $branch;
    }

    /**
     * @param  array<string, string|list<string>|null>  $headers
     * @return \Closure(string): ?string
     */
    private function headerReader(array $headers): \Closure
    {
        $lower = [];
        foreach ($headers as $name => $value) {
            if (is_array($value)) {
                $value = $value[0] ?? null;
            }
            if (is_scalar($value)) {
                $lower[strtolower((string) $name)] = trim((string) $value);
            }
        }

        return fn (string $name): ?string => $lower[$name] ?? null;
    }

    private function checkHmac(?string $given, string $prefix, string $body, string $secret): void
    {
        $expected = $prefix.hash_hmac('sha256', $body, $secret);

        if ($given === null || $given === '' || ! hash_equals($expected, strtolower($given))) {
            throw new WebhookRejected('Missing or wrong webhook signature.');
        }
    }

    /** @param  list<string>  $commitKeys  payload keys of the commit, first non-empty wins */
    private function event(string $provider, bool $isPush, string $body, array $commitKeys): WebhookEvent
    {
        if (! $isPush) {
            return new WebhookEvent($provider, WebhookEvent::KIND_OTHER, null, null);
        }

        $payload = $this->payload($body);
        $ref = is_string($payload['ref'] ?? null) && $payload['ref'] !== '' ? $payload['ref'] : null;

        $raw = null;
        foreach ($commitKeys as $key) {
            if (is_string($payload[$key] ?? null) && $payload[$key] !== '') {
                $raw = strtolower($payload[$key]);
                break;
            }
        }

        if ($raw === self::ZERO_COMMIT) {
            // A deleted branch: nothing to deploy.
            return new WebhookEvent($provider, WebhookEvent::KIND_OTHER, $ref, null);
        }

        $commit = $raw !== null && preg_match('/\A[0-9a-f]{40}\z/', $raw) === 1 ? $raw : null;

        return new WebhookEvent($provider, WebhookEvent::KIND_PUSH, $ref, $commit);
    }

    /**
     * The JSON payload; GitHub can also send it form-encoded as `payload=`.
     *
     * @return array<string, mixed>
     */
    private function payload(string $body): array
    {
        if (str_starts_with($body, 'payload=')) {
            parse_str($body, $form);
            $body = is_string($form['payload'] ?? null) ? $form['payload'] : '';
        }

        $data = json_decode($body, true);

        return is_array($data) ? $data : [];
    }
}
