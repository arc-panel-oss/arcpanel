<?php

namespace App\Http\Controllers\Ports;

use App\Auth\Principal;
use App\Auth\Role;
use App\Http\Controllers\Controller;
use App\Policies\PortPolicy;
use App\Services\Ports\PortService;
use App\Services\WebDomains\ApplyResult;
use App\Tenancy\TenancyDirectory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Ports of a user or a reseller (E12 spec §7.4, §7.5) and the actions on
 * one user's ports, also used from Admin → Server → Ports. Routes with
 * {user} run through `effective-user` (404 outside the scope).
 */
final class PortController extends Controller
{
    private const COMMENT = '/^[[:alnum:]][[:alnum:][:space:]._-]{0,64}[[:alnum:]]$/';

    public function __construct(
        private readonly PortService $ports,
        private readonly TenancyDirectory $tenancy,
    ) {}

    public function index(Principal $principal, PortPolicy $policy): View|RedirectResponse
    {
        if ($policy->viewServer($principal)) {
            return redirect()->route('server.ports');
        }
        abort_unless($policy->viewOwn($principal), 403);
        $user = $principal->effectiveUser();
        $isReseller = $principal->actingRole() === Role::Reseller;
        $customers = $isReseller ? $this->ports->accounts($this->tenancy->customersOf($user)) : [];

        $abilities = [];
        foreach ([$user, ...array_keys($customers)] as $name) {
            $abilities[$name] = $policy->abilities($principal, $name);
        }

        return view('ports.index', [
            'account' => $this->ports->account($user),
            'customers' => $customers,
            'isReseller' => $isReseller,
            'abilities' => $abilities,
            'canRequest' => $policy->request($principal),
            'owner' => $this->tenancy->ownerOf($user),
        ]);
    }

    public function reserve(Request $request, string $user, Principal $principal, PortPolicy $policy): RedirectResponse
    {
        abort_unless($policy->reserve($principal, $user), 403);
        $data = $request->validate([
            'port' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'proto' => ['required', 'in:tcp,udp'],
            'comment' => ['nullable', 'string', 'regex:'.self::COMMENT],
        ]);
        $port = isset($data['port']) ? (int) $data['port'] : null;

        return $this->done($this->ports->apply($this->ports->planReserve($user, $port, $data['proto'], (string) ($data['comment'] ?? ''))), __('The port has been reserved.'));
    }

    public function release(string $user, string $range, Principal $principal, PortPolicy $policy): RedirectResponse
    {
        abort_unless($policy->reserve($principal, $user), 403);
        [$start] = PortService::bounds($range);

        return $this->done($this->ports->apply($this->ports->planRelease($user, $start)), __('The port has been released.'));
    }

    public function public(Request $request, string $user, string $range, Principal $principal, PortPolicy $policy): RedirectResponse
    {
        $public = (string) $request->validate(['public' => ['required', 'in:yes,no']])['public'] === 'yes';
        [$port] = PortService::bounds($range);
        $entry = $this->ports->account($user)->entry($port);
        abort_if($entry === null, 404);
        abort_unless($policy->setPublic($principal, $user, $entry, $public), 403);

        return $this->done($this->ports->apply($this->ports->planPublic($user, $port, $public)),
            $public ? __('The port is open to the internet.') : __('The port is private.'));
    }

    public function publicOk(Request $request, string $user, string $range, Principal $principal, PortPolicy $policy): RedirectResponse
    {
        $publicOk = (string) $request->validate(['public_ok' => ['required', 'in:yes,no']])['public_ok'] === 'yes';
        abort_unless($policy->setPublicOk($principal, $user, $publicOk), 403);

        return $this->done($this->ports->apply($this->ports->planPublicOk($user, $range, $publicOk)), __('Changes have been saved.'));
    }

    public function destroy(Request $request, string $user, string $range, Principal $principal, PortPolicy $policy): RedirectResponse
    {
        abort_unless($policy->allocate($principal), 403);

        return $this->done($this->ports->apply($this->ports->planDelete($user, $range, $request->boolean('force'))), __('Ports have been deleted.'));
    }

    /** Free ports of the owner's (reseller's) pool to $user: a count or a range. */
    public function give(Request $request, string $user, Principal $principal, PortPolicy $policy): RedirectResponse
    {
        abort_unless($policy->give($principal, $user), 403);
        $data = $request->validate([
            'mode' => ['required', 'in:range,count'],
            'range' => ['nullable', 'required_if:mode,range', 'string', 'regex:/^\d{1,5}(-\d{1,5})?$/'],
            'count' => ['nullable', 'required_if:mode,count', 'integer', 'min:1', 'max:10000'],
        ]);
        $what = $data['mode'] === 'count' ? '+'.(int) $data['count'] : (string) $data['range'];

        return $this->done($this->ports->apply($this->ports->planMove($this->tenancy->ownerOf($user), $what, $user)), __('Ports have been given.'));
    }

    public function reclaim(string $user, string $range, Principal $principal, PortPolicy $policy): RedirectResponse
    {
        abort_unless($policy->reclaim($principal, $user), 403);

        return $this->done($this->ports->apply($this->ports->planMove($user, $range, $this->tenancy->ownerOf($user))), __('Ports have been taken back.'));
    }

    public function request(Request $request, Principal $principal, PortPolicy $policy): RedirectResponse
    {
        abort_unless($policy->request($principal), 403);
        $data = $request->validate([
            'count' => ['required', 'integer', 'min:1', 'max:1000'],
            'reason' => ['nullable', 'string', 'max:200', 'not_regex:/[<>\r\n]/'],
        ]);

        return $this->done($this->ports->apply($this->ports->planRequest($principal->effectiveUser(), (int) $data['count'], trim((string) ($data['reason'] ?? '')))),
            __('Your request has been sent.'));
    }

    private function done(ApplyResult $result, string $ok): RedirectResponse
    {
        return $result->ok()
            ? back()->with('ok', $ok)
            : back()->withInput()->with('error', sprintf(__('%s failed'), $result->failed?->label))->with('error_detail', $result->error);
    }
}
