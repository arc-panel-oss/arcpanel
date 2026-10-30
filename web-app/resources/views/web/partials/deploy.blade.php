{{--
    Deploy tab of a web domain (E20 spec §7,
    docs/features/specs/2026-10-29-001-e20-git-deploy-design.md; approved
    mockups in 2026-10-29-001-e20-git-deploy-mockups/). Off: the setup of
    pull or push mode. New: the steps that finish the setup. Active: the
    status, the releases with rollback, the recent deploys and the settings.
--}}
@php
    $cfg = $deploy['config'];
    $state = (string) ($cfg['STATE'] ?? 'off');
    $mode = (string) ($cfg['MODE'] ?? '');
    $canChange = $deploy['canChange'];
    $releases = $deploy['history']['releases'];
    $tasks = $deploy['history']['tasks'];
    $running = $deploy['running'];
    $issuers = $deploy['issuers'];
    $presets = ['none' => __('None: copy the files'), 'laravel' => __('Laravel'), 'static' => __('Static site (npm build)'), 'custom' => __('Custom script (.arcpanel/deploy.sh)')];
    $webroots = ['public' => __('public/ folder of the release'), 'dist' => __('dist/ folder of the release'), '.' => __('Root of the release')];
    $short = fn (string $commit) => $commit !== '' ? substr($commit, 0, 7) : '';
    $when = function (string $stamp, bool $year = false) {
        try {
            return $stamp !== '' ? \Illuminate\Support\Carbon::parse($stamp)->translatedFormat($year ? 'j M Y, H:i' : 'j M, H:i') : '';
        } catch (\Throwable) {
            return $stamp;
        }
    };
    $took = function (array $task) {
        try {
            $seconds = (int) \Illuminate\Support\Carbon::parse($task['START'])->diffInSeconds(\Illuminate\Support\Carbon::parse($task['END']));
        } catch (\Throwable) {
            return '';
        }

        return $seconds >= 60 ? sprintf(__('%1$d min %2$d s'), intdiv($seconds, 60), $seconds % 60) : sprintf(__('%d s'), $seconds);
    };
    $releaseOfTask = [];
    foreach ($releases as $release) {
        if (($release['TASK'] ?? '') !== '') {
            $releaseOfTask[$release['TASK']] = $release;
        }
    }
    $live = collect($releases)->firstWhere('CURRENT', 'yes');
    $lastTask = $tasks[0] ?? null;
    $once = $deployOnce ?? null;
    $base = ['user' => $record->user, 'domain' => $record->domain];
    $webroot = (string) ($cfg['WEBROOT'] ?? '.');
    $serves = $webroot === '.' ? 'current' : 'current/'.$webroot;
    $uploadUrl = (string) ($cfg['UPLOAD_URL'] ?? '');
    $branch = (string) ($cfg['BRANCH'] ?? '');
    $keyStep = ($cfg['AUTH'] ?? '') === 'key' && ($cfg['PUBLIC_KEY'] ?? '') !== '';
    $ciBranch = collect($cfg['PUBLISHERS'] ?? [])->map(fn ($p) => preg_replace('#^refs/heads/#', '', (string) ($p['REF'] ?? '')))->filter()->first() ?: 'main';
@endphp

<div class="ap-stack" x-data="apDeploy({ stateUrl: @js(route('web.domains.deploy.state', $base)), taskUrl: @js(route('web.domains.deploy.task', $base + ['task' => '00000000000000'])), running: @js($running !== null), lines: @js($running['lines'] ?? []) })">
    @if ($once !== null)
        {{-- A reload must not post the form again --}}
        <div x-data x-init="history.replaceState(null, '', {{ json_encode(route('web.domains.edit', $base).'#deploy') }})"></div>
    @endif

    @if ($errors->deploy->any())
        <x-ap-alert tone="danger" :title="__('Please check the form.')">{{ $errors->deploy->first() }}</x-ap-alert>
    @endif

    @if ($state === 'off')
        {{-- Setup --}}
        @if (($cfg['SUPPORTED'] ?? 'yes') !== 'yes')
            <x-ap-card :title="__('Git Deploy')" icon="git">
                <x-ap-alert tone="warn" :title="__('This domain cannot use Git Deploy')">
                    @if (($cfg['REASON'] ?? '') === 'template')
                        {{ __('Its PHP-FPM pool template does not let PHP read the releases and shared folders. Choose a backend template shipped with ArcPanel on the Advanced tab, or ask the administrator to add the two paths to the custom template.') }}
                    @else
                        {{ __('The domain has no PHP-FPM backend.') }}
                    @endif
                </x-ap-alert>
            </x-ap-card>
        @elseif (! $canChange)
            <x-ap-card :title="__('Git Deploy')" icon="git"><p class="muted">{{ __('Git Deploy is off for this domain.') }}</p></x-ap-card>
        @else
            <x-ap-card :title="__('Git Deploy')" icon="git" x-data="{ mode: @js(old('mode', 'pull')), auth: @js(old('auth', 'key')) }">
                <p class="muted">{{ __('Deploy this site from Git with atomic releases and one-click rollback. Each deploy goes into a new release folder; the site switches to it only when every step has passed.') }}</p>
                <x-ap-alert tone="info" :title="__('Your current files stay where they are')">{{ __('The site keeps serving public_html until the first deploy succeeds. Turning Git Deploy off later points the site back to it.') }}</x-ap-alert>
                <x-ap-form :action="route('web.domains.deploy.store', $base)">
                    <div x-on:change="if ($event.target.name === 'mode') mode = $event.target.value">
                        <x-ap-card-picker name="mode" :label="__('How do you deploy?')" :value="old('mode', 'pull')" :options="[
                            'pull' => ['title' => __('Pull from Git'), 'icon' => 'git', 'description' => __('The server fetches your repository and builds it with a preset. Works with GitHub, GitLab, Gitea or any Git host.'), 'meta' => __('Deploy key or token · webhook')],
                            'push' => ['title' => __('Push from CI'), 'icon' => 'upload', 'description' => __('Your CI (GitHub Actions, GitLab CI) builds the site and uploads it. The server stores no key, token or secret.'), 'meta' => __('OIDC · nothing stored on the server')],
                        ]" />
                    </div>

                    <template x-if="mode === 'pull'">
                        <div class="ap-stack">
                            <div class="ap-form-grid">
                                <x-ap-input name="url" :label="__('Repository')" mono required placeholder="git@github.com:acme/shop.git" bag="deploy" :hint="__('SSH (git@host:path) or HTTPS address.')" />
                                <x-ap-input name="branch" :label="__('Branch')" mono required :value="old('branch', 'main')" bag="deploy" :hint="__('Pushes to other branches are ignored.')" />
                            </div>
                            <div x-on:change="if ($event.target.name === 'auth') auth = $event.target.value">
                                <x-ap-radio-group name="auth" :label="__('Access to the repository')" :value="old('auth', 'key')" :options="['key' => __('Deploy key (recommended)'), 'token' => __('HTTPS token')]" />
                                <p class="ap-hint" x-show="auth === 'key'">{{ __('A key pair is created for this domain. You add its public part to the repository as a read-only deploy key.') }}</p>
                            </div>
                            <div x-show="auth === 'token'" x-cloak>
                                <x-ap-password name="token" :label="__('Access token')" :meter="false" bag="deploy" :hint="__('A token with read access to this repository only. It is stored encrypted and never shown again.')" />
                            </div>
                            <div class="ap-form-grid">
                                <x-ap-select name="preset" :label="__('Build preset')" :value="old('preset', 'laravel')" :options="$presets" bag="deploy"
                                    :hint="__('Laravel runs composer install, npm run build when present, migrations and optimize.')" />
                                <x-ap-select name="webroot" :label="__('Web root')" :value="old('webroot', 'public')" :options="$webroots" bag="deploy"
                                    :hint="__('The folder the site serves after the first deploy.')" />
                            </div>
                            <div class="ap-form-actions"><x-ap-button type="submit" variant="primary" icon="git">{{ __('Set up pull deploy') }}</x-ap-button></div>
                        </div>
                    </template>

                    <template x-if="mode === 'push'">
                        <div class="ap-stack">
                            <fieldset class="ap-field ap-fieldset">
                                <legend class="ap-label">{{ __('Who may deploy') }}</legend>
                                <p class="ap-hint">{{ __('The CI job that may upload releases for this site. You can add more after the setup.') }}</p>
                                <div class="ap-form-grid">
                                    <x-ap-select name="issuer" :label="__('CI provider')" :value="old('issuer', array_key_first($issuers))" :options="$issuers" bag="deploy" />
                                    <x-ap-input name="repo" :label="__('Repository')" mono required placeholder="acme/shop" bag="deploy" :hint="__('Owner and name, as in the repository URL.')" />
                                    <x-ap-input name="ref" :label="__('Branch')" mono :value="old('ref', 'main')" bag="deploy" :hint="__('Fill in the branch, the environment, or both.')" />
                                    <x-ap-input name="environment" :label="__('Environment')" mono placeholder="production" bag="deploy" :hint="__('Optional: the CI environment that must run the job.')" />
                                    <x-ap-input name="workflow" :label="__('Workflow file')" mono placeholder=".github/workflows/deploy.yml" bag="deploy" :hint="__('Optional: only this workflow may deploy.')" />
                                </div>
                            </fieldset>
                            <div class="ap-form-grid">
                                <x-ap-select name="webroot" :label="__('Web root')" :value="old('webroot', 'public')" :options="$webroots" bag="deploy" :hint="__('The folder the site serves after the first deploy.')" />
                            </div>
                            <div class="ap-form-actions"><x-ap-button type="submit" variant="primary" icon="upload">{{ __('Set up push deploy') }}</x-ap-button></div>
                        </div>
                    </template>
                </x-ap-form>
            </x-ap-card>
        @endif
    @else
        @if (($cfg['PORT_OPEN'] ?? 'no') !== 'yes')
            <x-ap-alert tone="warn" :title="__('The deploy port is closed')">{{ __('Webhooks and uploads cannot reach the server until the administrator opens the deploy port (Server settings → Web → Git Deploy). Deploy now still works.') }}</x-ap-alert>
        @endif

        {{-- Finish the setup (no release yet) --}}
        @if ($state === 'new' && $mode === 'pull')
            <x-ap-card :title="__('Finish the setup')" icon="git">
                <p class="muted">{{ sprintf(__('Git Deploy is set up for %1$s, branch %2$s. A few steps remain, in the Git host and here.'), $cfg['URL'], $branch) }}</p>
                @if ($keyStep)
                    <section class="ap-stack" aria-labelledby="deploy-step-key">
                        <h3 id="deploy-step-key">{{ __('1. Add the deploy key to the repository') }}</h3>
                        <p class="muted">{{ __('In GitHub: Settings → Deploy keys → Add deploy key. Leave “Allow write access” off: the server only reads.') }}</p>
                        @include('web.partials.deploy-copy', ['id' => 'deploy-pubkey', 'label' => __('Public key'), 'value' => $cfg['PUBLIC_KEY'], 'hint' => __('You can see the public key again under Settings at any time.')])
                    </section>
                    <hr class="ap-divider">
                @endif
                <section class="ap-stack" aria-labelledby="deploy-step-hook">
                    <h3 id="deploy-step-hook">{{ $keyStep ? __('2. Add the webhook') : __('1. Add the webhook') }}</h3>
                    <p class="muted">{{ __('In GitHub: Settings → Webhooks → Add webhook, content type application/json, event “Just the push event”. GitLab and Gitea work with the same values.') }}</p>
                    @if (($once['webhook_secret'] ?? '') !== '')
                        <x-ap-alert tone="warn" :title="__('Copy the secret now')">{{ __('This is the only time the webhook secret is shown. If it is lost, create a new one under Settings and update the webhook.') }}</x-ap-alert>
                    @endif
                    <div class="ap-stack">
                        @include('web.partials.deploy-copy', ['id' => 'deploy-hookurl', 'label' => __('Payload URL'), 'value' => $cfg['WEBHOOK_URL'] ?? '', 'hint' => null])
                        @if (($once['webhook_secret'] ?? '') !== '')
                            {{-- Leaving the page before the secret is copied asks first --}}
                            <div x-data="apAccessKeySecret()" x-on:copy="onCopy()" x-on:submit.window="onSubmit($event)">
                                @include('web.partials.deploy-copy', ['id' => 'deploy-hooksecret', 'label' => __('Secret'), 'value' => $once['webhook_secret'], 'hint' => null])
                            </div>
                        @else
                            <p class="ap-hint">{{ __('The webhook secret was shown when it was created. Lost it? Create a new one under Settings → Access and webhook.') }}</p>
                        @endif
                    </div>
                </section>
                <hr class="ap-divider">
                <section class="ap-stack" aria-labelledby="deploy-step-deploy">
                    <h3 id="deploy-step-deploy">{{ $keyStep ? __('3. Deploy') : __('2. Deploy') }}</h3>
                    <p class="muted">{{ sprintf(__('Push to %s, or start the first deploy now. Nothing changes for visitors until it succeeds.'), $branch) }}</p>
                    @if ($canChange)
                        <div class="ap-form-actions">
                            <x-ap-button variant="primary" icon="play" :form-action="route('web.domains.deploy.run', $base)" :disabled="$running !== null">{{ __('Deploy now') }}</x-ap-button>
                        </div>
                    @endif
                </section>
            </x-ap-card>
        @elseif ($mode === 'push')
            <x-ap-card :title="__('Add the deploy step to your CI')" icon="upload" x-data="{ ci: 'github' }">
                <p class="muted">{{ __('The workflow builds the site, asks its CI for a short-lived token, and uploads a tar.gz of the built files. The server checks the token against the trusted publishers below; there is no secret to store in the CI.') }}</p>
                @include('web.partials.deploy-copy', ['id' => 'deploy-upurl', 'label' => __('Upload URL'), 'value' => $uploadUrl, 'hint' => __('Also the token’s audience.')])
                <div class="ap-row"><div class="ap-segment" role="group" aria-label="{{ __('CI provider') }}">
                    <button type="button" x-on:click="ci = 'github'" x-bind:aria-pressed="(ci === 'github').toString()">GitHub Actions</button>
                    <button type="button" x-on:click="ci = 'gitlab'" x-bind:aria-pressed="(ci === 'gitlab').toString()">GitLab CI</button>
                </div></div>
                @php
                    $githubYaml = "name: Deploy\non: { push: { branches: [".$ciBranch."] } }\npermissions: { id-token: write, contents: read }\njobs:\n  deploy:\n    runs-on: ubuntu-latest\n    steps:\n      - uses: actions/checkout@v4\n      - run: composer install --no-dev && npm ci && npm run build\n      - run: tar czf release.tar.gz --exclude=.git .\n      - name: Upload to ArcPanel\n        env: { DEPLOY_URL: \"".$uploadUrl."\" }\n        run: |\n          TOKEN=\$(curl -sS -H \"Authorization: bearer \$ACTIONS_ID_TOKEN_REQUEST_TOKEN\" \\\n            \"\$ACTIONS_ID_TOKEN_REQUEST_URL&audience=\$DEPLOY_URL\" | jq -r .value)\n          curl -sSf -H \"Authorization: Bearer \$TOKEN\" --data-binary @release.tar.gz \"\$DEPLOY_URL\"";
                    $gitlabYaml = "deploy:\n  stage: deploy\n  rules: [{ if: '\$CI_COMMIT_BRANCH == \"".$ciBranch."\"' }]\n  id_tokens:\n    ARCPANEL_TOKEN: { aud: \"".$uploadUrl."\" }\n  script:\n    - composer install --no-dev && npm ci && npm run build\n    - tar czf release.tar.gz --exclude=.git .\n    - curl -sSf -H \"Authorization: Bearer \$ARCPANEL_TOKEN\" --data-binary @release.tar.gz \"".$uploadUrl."\"";
                @endphp
                <div x-show="ci === 'github'">
                    <div class="ap-between"><span class="mono muted">.github/workflows/deploy.yml</span><button type="button" class="ap-btn ap-btn-sm ap-btn-ghost" x-on:click="copy('gh', $refs.gh.innerText)"><x-ap-icon name="copy" /><span x-text="copied === 'gh' ? @js(__('Copied')) : @js(__('Copy'))">{{ __('Copy') }}</span></button></div>
                    <x-ap-code :label="__('GitHub Actions workflow')" x-ref="gh">{{ $githubYaml }}</x-ap-code>
                </div>
                <div x-show="ci === 'gitlab'" x-cloak>
                    <div class="ap-between"><span class="mono muted">.gitlab-ci.yml</span><button type="button" class="ap-btn ap-btn-sm ap-btn-ghost" x-on:click="copy('gl', $refs.gl.innerText)"><x-ap-icon name="copy" /><span x-text="copied === 'gl' ? @js(__('Copied')) : @js(__('Copy'))">{{ __('Copy') }}</span></button></div>
                    <x-ap-code :label="__('GitLab CI job')" x-ref="gl">{{ $gitlabYaml }}</x-ap-code>
                </div>
            </x-ap-card>

            <x-ap-card :title="__('Trusted publishers')" icon="shield-check" :flush="($cfg['PUBLISHERS'] ?? []) !== []">
                @if (($cfg['PUBLISHERS'] ?? []) === [])
                    <p class="muted">{{ __('No trusted publisher: no CI can deploy this site yet.') }}</p>
                @else
                    <ul class="ap-list">
                        @foreach ($cfg['PUBLISHERS'] as $publisher)
                            <li>
                                <span class="ap-list-icon"><x-ap-icon name="git" /></span>
                                <div class="ap-list-main">
                                    <b class="mono">{{ $publisher['SUBJECT_REPO'] }}</b>
                                    <span class="subtle">{{ implode(' · ', array_filter([
                                        \App\Services\Deploy\DeployService::issuerLabel($publisher['ISSUER']),
                                        $publisher['REF'] !== '' ? sprintf(__('branch %s'), preg_replace('#^refs/heads/#', '', $publisher['REF'])) : null,
                                        $publisher['ENVIRONMENT'] !== '' ? sprintf(__('environment %s'), $publisher['ENVIRONMENT']) : null,
                                        $publisher['WORKFLOW'] !== '' ? sprintf(__('workflow %s'), $publisher['WORKFLOW']) : null,
                                    ])) }}</span>
                                </div>
                                @if ($canChange)
                                    <x-ap-button variant="ghost" size="sm" :icon-only="true" icon="trash" :label="sprintf(__('Remove %s'), $publisher['SUBJECT_REPO'])"
                                        :form-action="route('web.domains.deploy.publishers.destroy', $base + ['pub' => $publisher['PUB']])" form-method="DELETE"
                                        :confirm="sprintf(__('Remove the publisher %s? Its workflow can no longer deploy this site.'), $publisher['SUBJECT_REPO'])" />
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @endif
                @if ($canChange)
                    <x-slot:footer>
                        <details class="ap-disclosure">
                            <summary>{{ __('Add publisher') }}</summary>
                            <x-ap-form :action="route('web.domains.deploy.publishers.store', $base)">
                                <div class="ap-form-grid">
                                    <x-ap-select name="issuer" :label="__('CI provider')" :options="$issuers" bag="deploy" />
                                    <x-ap-input name="repo" :label="__('Repository')" mono required placeholder="acme/shop" bag="deploy" />
                                    <x-ap-input name="ref" :label="__('Branch')" mono value="main" bag="deploy" :hint="__('Fill in the branch, the environment, or both.')" />
                                    <x-ap-input name="environment" :label="__('Environment')" mono placeholder="production" bag="deploy" />
                                    <x-ap-input name="workflow" :label="__('Workflow file')" mono placeholder=".github/workflows/deploy.yml" bag="deploy" />
                                </div>
                                <div class="ap-form-actions"><x-ap-button type="submit" icon="plus">{{ __('Add publisher') }}</x-ap-button></div>
                            </x-ap-form>
                        </details>
                    </x-slot:footer>
                @endif
            </x-ap-card>

            @if ($state === 'new')
                <x-ap-card>
                    <x-ap-empty icon="upload" :title="__('No release yet')">{{ sprintf(__('Commit the workflow and push to %s. The site keeps serving public_html until the first upload is deployed.'), $ciBranch) }}</x-ap-empty>
                </x-ap-card>
            @endif
        @endif

        {{-- A deploy in progress --}}
        @if ($running !== null)
            <x-ap-card :title="__('A deploy is running')" icon="activity">
                <x-slot:actions><span class="ap-row"><span class="ap-spinner" aria-hidden="true"></span>{{ __('Running') }}</span></x-slot:actions>
                <p class="muted">{{ __('The live release keeps serving until every step has passed. A request that arrives now waits and runs after this one; only the newest waiting request is kept.') }}</p>
                <div class="ap-log is-wrapped"><pre class="ap-log-body ap-task-log" tabindex="0" role="log" aria-live="polite" aria-label="{{ __('Deploy output') }}" x-text="lines.join('\n')">{{ implode("\n", $running['lines']) }}</pre></div>
            </x-ap-card>
        @endif

        @if ($state === 'active')
            <section class="ap-status-strip" aria-label="{{ __('Deploy status') }}">
                <div class="ap-status-cell">
                    <span class="ap-status-label">{{ __('Live release') }}</span>
                    <span class="ap-status-value"><span class="mono">{{ $live !== null ? ($short($live['COMMIT']) ?: $live['ID']) : $cfg['CURRENT'] }}</span></span>
                    <span class="ap-status-meta mono">{{ $cfg['CURRENT'] }}</span>
                </div>
                <div class="ap-status-cell">
                    <span class="ap-status-label">{{ __('Last deploy') }}</span>
                    <span class="ap-status-value">
                        @if ($running !== null)
                            <span class="ap-spinner" aria-hidden="true"></span>{{ __('Running') }}
                        @elseif ($lastTask !== null)
                            <span class="ap-status-dot {{ $lastTask['STATUS'] === 'succeeded' ? 'is-ok' : ($lastTask['STATUS'] === 'failed' ? 'is-danger' : '') }}" aria-hidden="true"></span>{{ $lastTask['STATUS'] === 'succeeded' ? __('Succeeded') : ($lastTask['STATUS'] === 'failed' ? __('Failed') : __('Waiting')) }}
                        @else
                            {{ __('None yet') }}
                        @endif
                    </span>
                    <span class="ap-status-meta">{{ $lastTask !== null ? implode(' · ', array_filter([$when($lastTask['END'] ?: $lastTask['QUEUED']), $lastTask['END'] !== '' ? $took($lastTask) : ''])) : '' }}</span>
                </div>
                <div class="ap-status-cell">
                    <span class="ap-status-label">{{ __('Source') }}</span>
                    @if ($mode === 'pull')
                        <span class="ap-status-value">{{ __('Pull') }} · <span class="mono">{{ $branch }}</span></span>
                        <span class="ap-status-meta mono ap-trunc">{{ $cfg['URL'] }}</span>
                    @else
                        <span class="ap-status-value">{{ __('Push from CI') }}</span>
                        <span class="ap-status-meta">{{ trans_choice(':count trusted publisher|:count trusted publishers', count($cfg['PUBLISHERS'] ?? []), ['count' => count($cfg['PUBLISHERS'] ?? [])]) }}</span>
                    @endif
                </div>
                <div class="ap-status-cell">
                    <span class="ap-status-label">{{ __('Build') }}</span>
                    <span class="ap-status-value">{{ $mode === 'pull' ? ($presets[$cfg['PRESET']] ?? $cfg['PRESET']) : __('By the CI') }}</span>
                    <span class="ap-status-meta">{{ __('Serves') }} <span class="mono">{{ $serves }}</span></span>
                </div>
            </section>

            <x-ap-card :title="__('Releases')" icon="layers" :flush="true">
                @if ($mode === 'pull' && $canChange)
                    <x-slot:actions>
                        <x-ap-button size="sm" variant="primary" icon="play" :form-action="route('web.domains.deploy.run', $base)" :disabled="$running !== null" :title="$running !== null ? __('A deploy is running') : null">{{ __('Deploy now') }}</x-ap-button>
                    </x-slot:actions>
                @endif
                <ul class="ap-releases">
                    @foreach ($releases as $release)
                        <li class="ap-release">
                            <div class="ap-release-main">
                                <div class="ap-release-head">
                                    <span class="ap-release-version mono">{{ $release['ID'] }}</span>
                                    @if ($release['CURRENT'] === 'yes')<x-ap-badge tone="ok">{{ __('Live') }}</x-ap-badge>@endif
                                    <span class="subtle">{{ $when($release['CREATED'], true) }} · {{ $release['SOURCE'] === 'archive' ? __('uploaded by CI') : __('pulled') }}</span>
                                </div>
                                @if ($release['COMMIT'] !== '')
                                    <p class="ap-release-reason"><x-ap-icon name="git" /><span><span class="mono">{{ $short($release['COMMIT']) }}</span> {{ preg_replace('#^refs/heads/#', '', $release['REF']) }}</span></p>
                                @endif
                            </div>
                            <div class="ap-release-action">
                                @if ($release['CURRENT'] === 'yes')
                                    <span class="subtle">{{ __('Serving now') }}</span>
                                @elseif ($canChange)
                                    <x-ap-button icon="rotate" :form-action="route('web.domains.deploy.rollback', $base + ['release' => $release['ID']])" :disabled="$running !== null"
                                        :confirm="sprintf(__('Roll back to %s? The site switches at once. Database migrations of later releases are not undone.'), $release['ID'])"
                                        :confirm-title="sprintf(__('Roll back %s'), $record->domain)" :confirm-accept="__('Roll back')">{{ __('Roll back') }}</x-ap-button>
                                @endif
                            </div>
                        </li>
                    @endforeach
                </ul>
                <x-slot:footer><span class="subtle">{{ sprintf(__('%1$d of %2$d kept releases. Older releases are removed after each deploy.'), count($releases), (int) $cfg['KEEP']) }}</span></x-slot:footer>
            </x-ap-card>
        @endif

        @if ($tasks !== [])
            <x-ap-card :title="__('Recent deploys')" icon="history" :flush="true">
                <ul class="ap-list">
                    @foreach (array_slice($tasks, 0, 10) as $task)
                        @php
                            $release = $releaseOfTask[$task['ID']] ?? null;
                            $title = match (true) {
                                $task['ACTION'] === 'rollback' && $task['STATUS'] === 'succeeded' => sprintf(__('Rolled back to %s'), $task['VALUE']),
                                $task['ACTION'] === 'rollback' => sprintf(__('Rollback to %s'), $task['VALUE']),
                                $task['STATUS'] === 'succeeded' && $release !== null => sprintf(__('Deployed %1$s to %2$s'), $short($release['COMMIT']) ?: __('the upload'), $release['ID']),
                                $task['STATUS'] === 'succeeded' => __('Deployed'),
                                in_array($task['STATUS'], ['running', 'queued'], true) => __('Deploying'),
                                default => __('Deploy failed'),
                            };
                            $source = match ($task['ACTION']) {
                                'archive' => __('upload from CI'),
                                'pull' => preg_match('/^[0-9a-f]{40}$/', $task['VALUE']) === 1 ? sprintf(__('webhook, commit %s'), $short($task['VALUE'])) : sprintf(__('branch %s'), $task['VALUE']),
                                default => __('rollback'),
                            };
                            $tone = match ($task['STATUS']) { 'succeeded' => 'ok', 'failed' => 'danger', 'running' => 'info', default => null };
                            $label = match ($task['STATUS']) { 'succeeded' => __('Succeeded'), 'failed' => __('Failed'), 'running' => __('Running'), default => __('Waiting') };
                            $icon = match ($task['STATUS']) { 'succeeded' => 'check-circle', 'failed' => 'x-circle', default => 'clock' };
                        @endphp
                        <li>
                            <span class="ap-list-icon">@if ($task['STATUS'] === 'running')<span class="ap-spinner" aria-hidden="true"></span>@else<x-ap-icon :name="$icon" />@endif</span>
                            <div class="ap-list-main">
                                <b>{{ $title }}</b>
                                <span class="subtle"><x-ap-badge :tone="$tone">{{ $label }}</x-ap-badge> {{ implode(' · ', array_filter([$when($task['QUEUED']), $source, $task['STATUS'] !== 'running' && $task['END'] !== '' ? $took($task) : null, $task['ERROR'] !== '' ? $task['ERROR'] : null])) }}</span>
                            </div>
                            <x-ap-button variant="ghost" size="sm" :icon-only="true" icon="file" :label="__('View log')" x-on:click="openLog({{ json_encode($task['ID']) }}, {{ json_encode($title) }})" />
                        </li>
                    @endforeach
                </ul>
            </x-ap-card>
        @endif

        {{-- Settings --}}
        @if ($canChange)
            <x-ap-card :title="__('Settings')" icon="sliders">
                <x-ap-form :action="route('web.domains.deploy.update', $base)">
                    <div class="ap-form-grid">
                        @if ($mode === 'pull')
                            <x-ap-input name="branch" :label="__('Branch')" mono :value="old('branch', $branch)" bag="deploy" />
                            <x-ap-select name="preset" :label="__('Build preset')" :value="old('preset', $cfg['PRESET'])" :options="$presets" bag="deploy" />
                        @endif
                        <x-ap-input name="webroot" :label="__('Web root')" mono :value="old('webroot', $webroot)" bag="deploy" :hint="__('. for the root of the release, or a folder such as public or dist.')" />
                        <x-ap-input name="keep" :label="__('Releases to keep')" type="number" :value="old('keep', $cfg['KEEP'])" min="1" max="20" bag="deploy" />
                        <x-ap-input name="shared" :label="__('Shared paths')" mono :value="old('shared', $cfg['SHARED'])" bag="deploy" :hint="__('Kept between releases and linked into each one. Edit .env in the File Manager under shared/.')" />
                        <x-ap-input name="health_path" :label="__('Health check path')" mono :value="old('health_path', $cfg['HEALTH_PATH'])" placeholder="/up" bag="deploy" :hint="__('Checked after the switch; a failure rolls back at once. Empty: no check.')" />
                    </div>
                    @if ($mode === 'pull')
                        <x-ap-switch name="migrate" :label="__('Run database migrations')" :description="__('php artisan migrate --force runs before the switch, so a failed migration keeps the live release. A rollback does not undo migrations.')" :checked="(bool) old('migrate', $cfg['MIGRATE'] === 'yes')" />
                    @endif
                    <div class="ap-form-actions"><x-ap-button type="submit" variant="primary" icon="check">{{ __('Save') }}</x-ap-button></div>
                </x-ap-form>

                <details class="ap-disclosure" @if ($once !== null && $state === 'active') open @endif>
                    <summary>{{ $mode === 'pull' ? __('Access and webhook') : __('Upload URL') }}</summary>
                    <div class="ap-stack">
                        <dl class="ap-dl">
                            @if ($mode === 'pull')
                                <dt>{{ __('Access') }}</dt><dd>{{ ($cfg['AUTH'] ?? '') === 'token' ? __('HTTPS token') : __('Deploy key') }}</dd>
                                @if ($keyStep)
                                    <dt>{{ __('Deploy key') }}</dt><dd class="mono ap-trunc" title="{{ $cfg['PUBLIC_KEY'] }}">{{ $cfg['PUBLIC_KEY'] }}</dd>
                                @endif
                                <dt>{{ __('Webhook URL') }}</dt><dd class="mono ap-trunc">{{ $cfg['WEBHOOK_URL'] }}</dd>
                            @else
                                <dt>{{ __('Upload URL') }}</dt><dd class="mono ap-trunc">{{ $uploadUrl }}</dd>
                            @endif
                        </dl>
                        @if (($once['webhook_secret'] ?? '') !== '' && $state === 'active')
                            <x-ap-alert tone="warn" :title="__('Copy the secret now')">{{ __('This is the only time the webhook secret is shown. Update it in the webhook of the Git host.') }}</x-ap-alert>
                            <div x-data="apAccessKeySecret()" x-on:copy="onCopy()" x-on:submit.window="onSubmit($event)">
                                @include('web.partials.deploy-copy', ['id' => 'deploy-newsecret', 'label' => __('New webhook secret'), 'value' => $once['webhook_secret'], 'hint' => null])
                            </div>
                        @endif
                        <div class="ap-actions">
                            @if ($mode === 'pull')
                                <x-ap-button size="sm" icon="key" :form-action="route('web.domains.deploy.credential', $base + ['kind' => 'key'])" :confirm="__('Create a new deploy key? The old key stops working; add the new public key to the repository before the next deploy.')">{{ __('New deploy key') }}</x-ap-button>
                                <x-ap-button size="sm" icon="refresh" :form-action="route('web.domains.deploy.credential', $base + ['kind' => 'webhook'])" :confirm="__('Create a new webhook secret? Deliveries with the old secret are refused until you update the webhook.')">{{ __('New webhook secret') }}</x-ap-button>
                            @endif
                            <x-ap-button size="sm" icon="link" :form-action="route('web.domains.deploy.credential', $base + ['kind' => 'id'])" :confirm="__('Create a new URL? The webhook and upload URLs change; update them in the Git host and the CI.')">{{ __('New URL') }}</x-ap-button>
                        </div>
                        @if ($mode === 'pull' && str_starts_with((string) $cfg['URL'], 'https://'))
                            <x-ap-form :action="route('web.domains.deploy.credential', $base + ['kind' => 'token'])">
                                <x-ap-password name="token" :label="__('New access token')" :meter="false" bag="deploy" :hint="__('Replaces the deploy key or the token: the server then fetches over HTTPS with this token.')" />
                                <div class="ap-form-actions"><x-ap-button type="submit" size="sm" icon="key">{{ __('Save token') }}</x-ap-button></div>
                            </x-ap-form>
                        @endif
                    </div>
                </details>
                <details class="ap-disclosure">
                    <summary>{{ __('Turn off Git Deploy') }}</summary>
                    <div class="ap-stack">
                        <p class="muted">{{ __('The site goes back to its previous document root. The deploy key and the webhook secret are deleted; the folders releases/ and shared/ stay on disk until you delete them.') }}</p>
                        <div class="ap-actions"><x-ap-button variant="danger" icon="x" :form-action="route('web.domains.deploy.destroy', $base)" form-method="DELETE" :confirm="sprintf(__('Turn off Git Deploy for %s? The site will serve its previous document root again.'), $record->domain)" :confirm-accept="__('Turn off')">{{ __('Turn off Git Deploy') }}</x-ap-button></div>
                    </div>
                </details>
            </x-ap-card>
        @endif
    @endif

    <x-ap-drawer id="deploy-log" :title="__('Deploy log')">
        <p class="ap-hint" x-text="drawer.title"></p>
        <p class="ap-error" x-show="drawer.error" x-text="drawer.error" x-cloak role="alert"></p>
        <div class="ap-log is-wrapped"><div class="ap-log-body" tabindex="0" role="log" aria-label="{{ __('Deploy log') }}">
            <template x-if="drawer.loading"><div class="ap-log-line muted">{{ __('Loading…') }}</div></template>
            <template x-for="(line, index) in drawer.lines" :key="index"><div class="ap-log-line" x-text="line"></div></template>
        </div></div>
    </x-ap-drawer>
</div>
