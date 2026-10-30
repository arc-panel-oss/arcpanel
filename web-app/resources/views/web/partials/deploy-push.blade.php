{{--
    Push mode of the Deploy tab (E20 spec §7): the CI step with the upload URL
    and the example workflows, and the trusted publishers. $folded puts the
    CI step in a disclosure once the deploy is active.
--}}
@php
    $githubYaml = "name: Deploy\non: { push: { branches: [".$ciBranch."] } }\npermissions: { id-token: write, contents: read }\njobs:\n  deploy:\n    runs-on: ubuntu-latest\n    steps:\n      - uses: actions/checkout@v4\n      - run: composer install --no-dev && npm ci && npm run build\n      - run: tar czf release.tar.gz --exclude=.git .\n      - name: Upload to ArcPanel\n        env: { DEPLOY_URL: \"".$uploadUrl."\" }\n        run: |\n          TOKEN=\$(curl -sS -H \"Authorization: bearer \$ACTIONS_ID_TOKEN_REQUEST_TOKEN\" \\\n            \"\$ACTIONS_ID_TOKEN_REQUEST_URL&audience=\$DEPLOY_URL\" | jq -r .value)\n          curl -sSf -H \"Authorization: Bearer \$TOKEN\" --data-binary @release.tar.gz \"\$DEPLOY_URL\"";
    $gitlabYaml = "deploy:\n  stage: deploy\n  rules: [{ if: '\$CI_COMMIT_BRANCH == \"".$ciBranch."\"' }]\n  id_tokens:\n    ARCPANEL_TOKEN: { aud: \"".$uploadUrl."\" }\n  script:\n    - composer install --no-dev && npm ci && npm run build\n    - tar czf release.tar.gz --exclude=.git .\n    - curl -sSf -H \"Authorization: Bearer \$ARCPANEL_TOKEN\" --data-binary @release.tar.gz \"".$uploadUrl."\"";
@endphp
<x-ap-card :title="$folded ? __('CI workflow') : __('Add the deploy step to your CI')" icon="upload" x-data="{ ci: 'github' }">
    @if ($folded)
        <p class="muted">{{ sprintf(__('Each push to %s that passes the workflow uploads a new release.'), $ciBranch) }}</p>
        <details class="ap-disclosure">
            <summary>{{ __('Show the upload URL and the workflows') }}</summary>
            <div class="ap-stack">
    @endif
    <p class="muted">{{ __('The workflow builds the site, asks its CI for a short-lived token, and uploads a tar.gz of the built files. The server checks the token against the trusted publishers below; there is no secret to store in the CI.') }}</p>
    @include('web.partials.deploy-copy', ['id' => 'deploy-upurl', 'label' => __('Upload URL'), 'value' => $uploadUrl, 'hint' => __('Also the token’s audience.')])
    <div class="ap-row"><div class="ap-segment" role="group" aria-label="{{ __('CI provider') }}">
        <button type="button" x-on:click="ci = 'github'" x-bind:aria-pressed="(ci === 'github').toString()">GitHub Actions</button>
        <button type="button" x-on:click="ci = 'gitlab'" x-bind:aria-pressed="(ci === 'gitlab').toString()">GitLab CI</button>
    </div></div>
    <div x-show="ci === 'github'">
        <div class="ap-between"><span class="mono muted">.github/workflows/deploy.yml</span><button type="button" class="ap-btn ap-btn-sm ap-btn-ghost" x-on:click="copy('gh', $refs.gh.innerText)"><x-ap-icon name="copy" /><span x-text="copied === 'gh' ? @js(__('Copied')) : @js(__('Copy'))">{{ __('Copy') }}</span></button></div>
        <x-ap-code :label="__('GitHub Actions workflow')" x-ref="gh">{{ $githubYaml }}</x-ap-code>
    </div>
    <div x-show="ci === 'gitlab'" x-cloak>
        <div class="ap-between"><span class="mono muted">.gitlab-ci.yml</span><button type="button" class="ap-btn ap-btn-sm ap-btn-ghost" x-on:click="copy('gl', $refs.gl.innerText)"><x-ap-icon name="copy" /><span x-text="copied === 'gl' ? @js(__('Copied')) : @js(__('Copy'))">{{ __('Copy') }}</span></button></div>
        <x-ap-code :label="__('GitLab CI job')" x-ref="gl">{{ $gitlabYaml }}</x-ap-code>
    </div>
    @if ($folded)
            </div>
        </details>
    @endif
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
