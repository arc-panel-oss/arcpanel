<?php

namespace App\Services\Users;

use App\Arc\ArcCommand;
use App\Arc\CommandResult;
use App\Arc\Options;
use App\Arc\Secret;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

/**
 * Two-factor enrolment and recovery codes (E39 W5 spec §11). The secret and
 * the codes are answered to the page that asked for them and never stored
 * by the panel; the QR code is drawn here, so the secret never leaves the
 * server.
 */
final class TwoFactorService
{
    /** The base32 TOTP secret of v-add-user-2fa. */
    public const SECRET = '/^[A-Z2-7]{32}$/';

    public function __construct(private readonly ArcCommand $arc) {}

    /**
     * `v-add-user-2fa USER json`: a new pending enrolment.
     *
     * @return array{secret: string, uri: string}|null null when 2FA is on already or the command failed
     */
    public function start(string $user): ?array
    {
        $result = $this->arc->run('v-add-user-2fa', [$user, 'json'], Options::allowFailure());
        $data = $result->ok() ? json_decode($result->stdout, true) : null;
        $secret = is_array($data) ? (string) ($data['SECRET'] ?? '') : '';
        $uri = is_array($data) ? (string) ($data['URI'] ?? '') : '';
        if (preg_match(self::SECRET, $secret) !== 1 || ! self::isUriOf($uri, $secret)) {
            return null;
        }

        return ['secret' => $secret, 'uri' => $uri];
    }

    /** Whether $uri is the otpauth URI that v-add-user-2fa prints for $secret. */
    public static function isUriOf(string $uri, string $secret): bool
    {
        return preg_match('#^otpauth://totp/[A-Za-z0-9%._~-]+\?secret='.preg_quote($secret, '#').'&issuer=ArcPanel&algorithm=SHA1&digits=6&period=30$#', $uri) === 1;
    }

    /**
     * `v-change-user-2fa USER CODE json`: confirm the enrolment.
     *
     * @return list<string>|null the recovery codes, null for a wrong code or an expired enrolment
     */
    public function confirm(string $user, string $code): ?array
    {
        $result = $this->arc->run('v-change-user-2fa', [$user, $code, 'json'], Options::allowFailure());

        return $result->ok() ? self::codes($result) : null;
    }

    /**
     * `v-add-user-2fa-recovery-codes USER json`: ten new codes.
     *
     * @return list<string>|null
     */
    public function newRecoveryCodes(string $user): ?array
    {
        $result = $this->arc->run('v-add-user-2fa-recovery-codes', [$user, 'json'], Options::allowFailure());

        return $result->ok() ? self::codes($result) : null;
    }

    /**
     * `v-check-user-2fa-recovery-code USER CODE_FILE IP`: the code goes in
     * a file. Answers the number of codes left, or null for a wrong code.
     */
    public function useRecoveryCode(string $user, string $code, string $ip): ?int
    {
        $result = $this->arc->run('v-check-user-2fa-recovery-code', [$user, Secret::file($code), $ip], Options::allowFailure());
        $left = trim($result->stdout);

        return $result->ok() && ctype_digit($left) ? (int) $left : null;
    }

    /** The otpauth URI as an SVG QR code, for an img element. */
    public static function qrDataUri(string $uri): string
    {
        $svg = (new Writer(new ImageRenderer(new RendererStyle(220, 2), new SvgImageBackEnd)))->writeString($uri);

        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }

    /** The codes as a text file to download. */
    public static function codesFile(string $user, array $codes): string
    {
        $text = sprintf("ArcPanel recovery codes of %s\n\n%s\n", $user, implode("\n", $codes));

        return 'data:text/plain;charset=utf-8,'.rawurlencode($text);
    }

    /** @return list<string>|null */
    private static function codes(CommandResult $result): ?array
    {
        $data = json_decode($result->stdout, true);
        $codes = is_array($data) && is_array($data['RECOVERY_CODES'] ?? null) ? $data['RECOVERY_CODES'] : [];
        $codes = array_values(array_filter($codes, fn ($code) => is_string($code) && preg_match('/^([A-Z2-7]{4}-){3}[A-Z2-7]{4}$/', $code) === 1));

        return $codes !== [] ? $codes : null;
    }
}
