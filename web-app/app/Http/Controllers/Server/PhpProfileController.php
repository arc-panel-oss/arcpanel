<?php

namespace App\Http\Controllers\Server;

use App\Arc\ExitCode;
use App\Auth\Principal;
use App\Http\Controllers\Controller;
use App\Http\Requests\Server\PhpProfileRequest;
use App\Policies\ServerPolicy;
use App\Services\Php\PhpManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * PHP-FPM profiles (E41 spec §7, §8): named pool settings that packages
 * allow and web domains choose. Administrators only.
 */
final class PhpProfileController extends Controller
{
    /** The values of a new profile: those of the shipped "small" profile. */
    private const NEW_PROFILE = [
        'DESCRIPTION' => '', 'PM' => 'ondemand', 'MAX_CHILDREN' => '4', 'START_SERVERS' => '', 'MIN_SPARE' => '', 'MAX_SPARE' => '',
        'MAX_REQUESTS' => '500', 'IDLE_TIMEOUT' => '10s', 'REQUEST_TERMINATE_TIMEOUT' => '', 'MEMORY_LIMIT' => '',
        'UPLOAD_MAX_FILESIZE' => '', 'POST_MAX_SIZE' => '', 'MAX_EXECUTION_TIME' => '', 'MAX_INPUT_VARS' => '',
    ];

    public function __construct(private readonly PhpManager $php) {}

    public function index(Principal $principal, ServerPolicy $policy): View
    {
        abort_unless($policy->viewPhp($principal), 403);

        return view('server.php.profiles.index', [
            'profiles' => $this->php->profiles(),
            'canManage' => $policy->managePhp($principal),
        ]);
    }

    public function create(Principal $principal, ServerPolicy $policy): View
    {
        abort_unless($policy->managePhp($principal), 403);

        return view('server.php.profiles.form', ['profile' => null, 'values' => self::NEW_PROFILE]);
    }

    public function edit(string $profile, Principal $principal, ServerPolicy $policy): View
    {
        abort_unless($policy->viewPhp($principal), 403);
        $row = $this->php->profile($profile);
        abort_if($row === null, 404);

        return view('server.php.profiles.form', [
            'profile' => $row,
            'values' => $row['values'],
            'canManage' => $policy->managePhp($principal),
        ]);
    }

    public function store(PhpProfileRequest $request, Principal $principal, ServerPolicy $policy): RedirectResponse
    {
        abort_unless($policy->managePhp($principal), 403);
        $name = (string) $request->input('name');

        $result = $this->php->addProfile($name, $request->values());
        if (! $result->ok()) {
            return back()->withInput()->with('error', $result->errorMessage());
        }

        return redirect()->route('server.php.profiles.index')
            ->with('ok', sprintf(__('Profile %s has been created.'), $name))
            ->with('ok_detail', trim($result->stdout));
    }

    public function update(PhpProfileRequest $request, string $profile, Principal $principal, ServerPolicy $policy): RedirectResponse
    {
        abort_unless($policy->managePhp($principal), 403);
        abort_if($this->php->profile($profile) === null, 404);

        $result = $this->php->changeProfile($profile, $request->values());
        if (! $result->ok()) {
            return back()->withInput()->with('error', $result->errorMessage());
        }

        return redirect()->route('server.php.profiles.index')
            ->with('ok', sprintf(__('Profile %s has been saved; the pools that use it have been rewritten.'), $profile))
            ->with('ok_detail', trim($result->stdout));
    }

    public function destroy(Request $request, string $profile, Principal $principal, ServerPolicy $policy): RedirectResponse
    {
        abort_unless($policy->managePhp($principal), 403);
        abort_if($this->php->profile($profile) === null, 404);

        $result = $this->php->deleteProfile($profile, $request->boolean('force'));
        $back = redirect()->route('server.php.profiles.index');
        if (! $result->ok()) {
            return $back->with('error', $result->exit() === ExitCode::InUse
                ? sprintf(__('Profile %s is in use. Confirm moving its domains to the default profile.'), $profile)
                : $result->errorMessage())->with('error_detail', $result->errorMessage());
        }

        return $back->with('ok', sprintf(__('Profile %s has been deleted.'), $profile));
    }
}
