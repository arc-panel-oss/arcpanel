<?php

namespace App\Http\Controllers;

use App\Auth\Principal;
use App\Policies\ServerPolicy;
use App\Policies\WebDomainPolicy;
use App\Services\WebDomains\CsrGenerator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * The CSR generator of the SSL forms (web/generate/ssl), E7 batch 3 spec
 * §12.2: for a web domain of the owner, or for the panel host name.
 */
final class CsrController extends Controller
{
    public function __construct(private readonly CsrGenerator $generator) {}

    /** POST /app/web/domains/{user}/{domain}/ssl/generate */
    public function webDomain(Request $request, string $user, string $domain, Principal $principal, WebDomainPolicy $policy): JsonResponse
    {
        abort_unless($policy->generateCsr($principal, $user), 403);

        return $this->generate($request);
    }

    /** POST /app/server/settings/ssl/csr */
    public function server(Request $request, Principal $principal, ServerPolicy $policy): JsonResponse
    {
        abort_unless($policy->editSettings($principal), 403);

        return $this->generate($request);
    }

    private function generate(Request $request): JsonResponse
    {
        $fields = $request->validate(CsrGenerator::rules());
        $aliases = CsrGenerator::aliases((string) ($fields['aliases'] ?? ''));
        if ($aliases === null) {
            throw ValidationException::withMessages(['aliases' => __('Please enter valid domain names, one per line.')]);
        }

        $answer = $this->generator->generate(array_map('strval', $fields + ['key_algo' => '']), $aliases);
        if (! $answer['result']->ok() || $answer['crt'] === '') {
            $message = $answer['result']->message() !== '' ? $answer['result']->message() : sprintf(__('Error code: %s'), $answer['result']->exitCode);

            return response()->json(['error' => ['code' => 'E_COMMAND', 'message' => $message]], 422);
        }

        return response()->json(['crt' => $answer['crt'], 'key' => $answer['key'], 'csr' => $answer['csr']])
            ->header('Cache-Control', 'no-store');
    }
}
