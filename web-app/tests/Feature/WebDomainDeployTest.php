<?php

use App\Arc\ExitCode;

/*
 * The Deploy tab of a web domain (E20,
 * docs/features/specs/2026-10-29-001-e20-git-deploy-design.md §7).
 */

const DEPLOY_TAB = '/web/domains/bob/example.com/edit';
const DEPLOY_BASE = '/web/domains/bob/example.com/deploy';

function deployActive(array $overrides = []): array
{
    return deployRow(array_replace([
        'ID' => 'k3v9q2m7x1c8', 'MODE' => 'pull', 'URL' => 'git@github.com:acme/shop.git', 'BRANCH' => 'main', 'AUTH' => 'key',
        'PRESET' => 'laravel', 'SHARED' => 'storage/ .env', 'KEEP' => '5', 'WEBROOT' => 'public', 'MIGRATE' => 'yes',
        'HEALTH_PATH' => '/up', 'STATE' => 'active', 'CURRENT' => '20261029-142501',
        'WEBHOOK_URL' => 'https://panel.example.com:8088/hooks/k3v9q2m7x1c8', 'PORT_OPEN' => 'yes',
        'PUBLIC_KEY' => 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIM6v0x9Qf3kq2r1mJt8Yb7nZ0wXcL4pEo5sHd2aG9uVf arcpanel-deploy@example.com',
    ], $overrides));
}

function deployHistory(): array
{
    return [
        'releases' => [
            ['ID' => '20261029-142501', 'SOURCE' => 'pull', 'COMMIT' => 'a1b2c3d4e5f60718293a4b5c6d7e8f9012345678', 'REF' => 'refs/heads/main', 'CREATED' => '2026-10-29 14:25:01', 'TASK' => '17617400001234', 'CURRENT' => 'yes'],
            ['ID' => '20261028-091744', 'SOURCE' => 'pull', 'COMMIT' => '9f8e7d6c5b4a39281706f5e4d3c2b1a098765432', 'REF' => 'refs/heads/main', 'CREATED' => '2026-10-28 09:17:44', 'TASK' => '17616400001111', 'CURRENT' => 'no'],
        ],
        'tasks' => [
            ['ID' => '17617400001234', 'ACTION' => 'pull', 'VALUE' => 'main', 'STATUS' => 'succeeded', 'EXIT' => '0', 'ERROR' => '', 'QUEUED' => '2026-10-29 14:24:58', 'START' => '2026-10-29 14:24:58', 'END' => '2026-10-29 14:26:10'],
            ['ID' => '17617300009999', 'ACTION' => 'pull', 'VALUE' => 'main', 'STATUS' => 'failed', 'EXIT' => '19', 'ERROR' => 'health check failed, rolled back to 20261028-091744', 'QUEUED' => '2026-10-29 13:58:00', 'START' => '2026-10-29 13:58:00', 'END' => '2026-10-29 13:59:00'],
        ],
    ];
}

beforeEach(function () {
    $this->fake = fakeArc();
    fakeResellers($this->fake);
    fakeWebDomains($this->fake);
    $this->fake->on('v-list-web-domain-php')->returnsJson(domainPhpRow());
});

// Visibility and scope

it('offers the setup of both modes when deploy is off', function () {
    signIn('bob', 'user');

    $this->get(DEPLOY_TAB)->assertOk()
        ->assertSee('Deploy')->assertSee('Git Deploy')
        ->assertSee('Pull from Git')->assertSee('Push from CI')
        ->assertSee('action="http://localhost'.DEPLOY_BASE.'"', false)
        ->assertSee('Your current files stay where they are');
});

it('hides the tab when the package does not allow deploy and it is off', function () {
    $this->fake->on('v-list-web-domain-deploy')->returnsJson(['example.com' => deployRow(['SUPPORTED' => 'no', 'REASON' => 'package'])]);
    signIn('bob', 'user');

    $this->get(DEPLOY_TAB)->assertOk()->assertDontSee('Pull from Git')->assertDontSee('x-ap-tab="deploy"', false);
});

it('explains a pool template without the release paths instead of the form', function () {
    $this->fake->on('v-list-web-domain-deploy')->returnsJson(['example.com' => deployRow(['SUPPORTED' => 'no', 'REASON' => 'template'])]);
    signIn('bob', 'user');

    $this->get(DEPLOY_TAB)->assertOk()->assertSee('cannot use Git Deploy')->assertDontSee('Set up pull deploy');
});

it('keeps the deploy of another reseller out of reach', function () {
    signIn('resb', 'reseller');

    $this->post(DEPLOY_BASE.'/run')->assertNotFound();
    $this->get(DEPLOY_BASE.'/state')->assertNotFound();
    $this->fake->assertNotRan('v-schedule-web-domain-deploy');
});

it('lets the owning reseller and the administrator manage it', function (string $who, string $role) {
    $this->fake->on('v-list-web-domain-deploy')->returnsJson(['example.com' => deployActive()]);
    $this->fake->on('v-schedule-web-domain-deploy')->returnsJson(['ID' => '17617400005555']);
    signIn($who, $role);

    $this->post(DEPLOY_BASE.'/run')->assertRedirect(DEPLOY_TAB.'#deploy')->assertSessionHas('ok');
})->with([['hostco', 'reseller'], ['arcadmin', 'admin']]);

// Setup

it('sets up pull mode and shows the webhook secret once', function () {
    $added = false;
    $this->fake->on('v-add-web-domain-deploy')->using(function () use (&$added) {
        $added = true;

        return [0, "PUBLIC_KEY='ssh-ed25519 AAAAkey arcpanel-deploy@example.com'\nWEBHOOK_SECRET='Secret0123456789abcdefghijABCDEFGHIJ0123'\n"];
    });
    // Off before the setup, new after it
    $this->fake->on('v-list-web-domain-deploy')->using(function () use (&$added) {
        return [0, json_encode(['example.com' => $added
            ? deployActive(['STATE' => 'new', 'CURRENT' => '', 'PUBLIC_KEY' => 'ssh-ed25519 AAAAkey arcpanel-deploy@example.com'])
            : deployRow()])];
    });
    $this->fake->on('v-change-web-domain-deploy')->returns();
    signIn('bob', 'user');

    $this->post(DEPLOY_BASE, ['mode' => 'pull', 'url' => 'git@github.com:acme/shop.git', 'branch' => 'main', 'auth' => 'key', 'preset' => 'laravel', 'webroot' => 'public'])
        ->assertOk()->assertSee('Finish the setup')->assertSee('Secret0123456789abcdefghijABCDEFGHIJ0123')
        ->assertSee('ssh-ed25519 AAAAkey arcpanel-deploy@example.com')
        ->assertSee('This is the only time the webhook secret is shown.');
    $this->fake->assertRan('v-add-web-domain-deploy', fn ($a) => $a === ['bob', 'example.com', 'pull', 'git@github.com:acme/shop.git', 'main', 'laravel', 'key']);
});

it('stores a token through a file, never as an argument', function () {
    $this->fake->on('v-add-web-domain-deploy')->returns("WEBHOOK_SECRET='Secret0123456789abcdefghijABCDEFGHIJ0123'\n");
    $this->fake->on('v-change-web-domain-deploy-credential')->returns();
    $this->fake->on('v-change-web-domain-deploy')->returns();
    signIn('bob', 'user');

    $this->post(DEPLOY_BASE, ['mode' => 'pull', 'url' => 'https://github.com/acme/shop.git', 'branch' => 'main', 'auth' => 'token', 'token' => 'ghp_example_token', 'preset' => 'none', 'webroot' => '.'])
        ->assertOk();
    $this->fake->assertRan('v-change-web-domain-deploy-credential', fn ($a) => $a[2] === 'token' && $a[3] === 'ghp_example_token');
});

it('refuses a local repository, a bad branch and a token over SSH before any command', function (array $input, string $field) {
    signIn('bob', 'user');

    $this->post(DEPLOY_BASE, array_replace(['mode' => 'pull', 'url' => 'git@github.com:acme/shop.git', 'branch' => 'main', 'auth' => 'key', 'preset' => 'none', 'webroot' => '.'], $input))
        ->assertRedirect(DEPLOY_TAB.'#deploy')->assertSessionHasErrors($field, null, 'deploy');
    $this->fake->assertNotRan('v-add-web-domain-deploy');
})->with([
    'file url' => [['url' => 'file:///srv/repo.git'], 'url'],
    'local path' => [['url' => '/srv/repo.git'], 'url'],
    'branch' => [['branch' => 'main..x'], 'branch'],
    'token over ssh' => [['auth' => 'token', 'token' => 'x'], 'url'],
    'preset' => [['preset' => 'symfony'], 'preset'],
]);

it('sets up push mode with its first trusted publisher', function () {
    $this->fake->on('v-add-web-domain-deploy')->returns();
    $this->fake->on('v-change-web-domain-deploy')->returns();
    $this->fake->on('v-list-sys-deploy')->returnsJson(['DEPLOY_PORT' => '8088', 'DEPLOY_MAX_ARTIFACT' => '512M', 'DEPLOY_BUILD_TIMEOUT' => '900', 'DEPLOY_OIDC_ISSUERS' => 'https://token.actions.githubusercontent.com,https://gitlab.com', 'DOMAINS' => '1', 'PORT_OPEN' => 'yes']);
    $this->fake->on('v-add-web-domain-deploy-publisher')->returnsJson(['ID' => 'p1']);
    signIn('bob', 'user');

    $this->post(DEPLOY_BASE, ['mode' => 'push', 'issuer' => 'https://token.actions.githubusercontent.com', 'repo' => 'acme/shop', 'ref' => 'main', 'environment' => '', 'workflow' => '.github/workflows/deploy.yml', 'webroot' => 'public'])
        ->assertRedirect(DEPLOY_TAB.'#deploy')->assertSessionHas('ok');
    $this->fake->assertRan('v-add-web-domain-deploy', fn ($a) => $a === ['bob', 'example.com', 'push']);
    $this->fake->assertRan('v-add-web-domain-deploy-publisher', fn ($a) => $a === ['bob', 'example.com', 'https://token.actions.githubusercontent.com', 'acme/shop', 'refs/heads/main', '', '.github/workflows/deploy.yml', 'json']);
});

it('needs a branch or an environment for a publisher', function () {
    signIn('bob', 'user');

    $this->post(DEPLOY_BASE, ['mode' => 'push', 'issuer' => 'https://token.actions.githubusercontent.com', 'repo' => 'acme/shop', 'ref' => '', 'environment' => '', 'webroot' => '.'])
        ->assertSessionHasErrors('ref', null, 'deploy');
    $this->fake->assertNotRan('v-add-web-domain-deploy');
});

// Active state

it('shows the live release, the releases with rollback and the recent deploys', function () {
    $this->fake->on('v-list-web-domain-deploy')->returnsJson(['example.com' => deployActive()]);
    $this->fake->on('v-list-web-domain-deploys')->returnsJson(deployHistory());
    signIn('bob', 'user');

    $this->get(DEPLOY_TAB)->assertOk()
        ->assertSee('Live release')->assertSee('a1b2c3d')
        ->assertSee('20261029-142501')->assertSee('20261028-091744')
        ->assertSee('Roll back')->assertSee('Database migrations of later releases are not undone.')
        ->assertSee('health check failed, rolled back to 20261028-091744')
        ->assertSee('Deploy now')->assertSee('Turn off Git Deploy')
        ->assertSee('arcpanel-deploy@example.com');
});

it('starts a deploy, waits while one runs, and rolls back', function () {
    $this->fake->on('v-list-web-domain-deploy')->returnsJson(['example.com' => deployActive()]);
    $this->fake->on('v-schedule-web-domain-deploy')->returnsJson(['ID' => '17617400005555']);
    $this->fake->on('v-rollback-web-domain-deploy')->returnsJson(['ID' => '17617400006666']);
    signIn('bob', 'user');

    $this->post(DEPLOY_BASE.'/run')->assertRedirect(DEPLOY_TAB.'#deploy')->assertSessionHas('ok', 'The deploy has started.');
    $this->fake->assertRan('v-schedule-web-domain-deploy', fn ($a) => $a === ['bob', 'example.com', '', 'json']);
    $this->post(DEPLOY_BASE.'/rollback/20261028-091744')->assertSessionHas('ok', 'The rollback has started.');
    $this->fake->assertRan('v-rollback-web-domain-deploy', fn ($a) => $a === ['bob', 'example.com', '20261028-091744', 'json']);
    $this->post(DEPLOY_BASE.'/rollback/..%2Fetc')->assertNotFound();
});

it('saves only the settings that changed', function () {
    $this->fake->on('v-list-web-domain-deploy')->returnsJson(['example.com' => deployActive()]);
    $this->fake->on('v-change-web-domain-deploy')->returns();
    signIn('bob', 'user');

    $this->post(DEPLOY_BASE.'/settings', ['branch' => 'main', 'preset' => 'laravel', 'keep' => '10', 'shared' => 'storage/ .env', 'health_path' => '', 'migrate' => '1', 'webroot' => 'public'])
        ->assertRedirect(DEPLOY_TAB.'#deploy')->assertSessionHas('ok', 'Changes have been saved.');
    expect(collect($this->fake->calls('v-change-web-domain-deploy'))->pluck('args')->all())->toBe([
        ['bob', 'example.com', 'KEEP', '10'],
        ['bob', 'example.com', 'HEALTH_PATH', ''],
    ]);
    $this->post(DEPLOY_BASE.'/settings', ['branch' => 'main', 'preset' => 'laravel', 'keep' => '21', 'shared' => '/etc/passwd', 'health_path' => 'up', 'migrate' => '1', 'webroot' => 'public'])
        ->assertSessionHasErrors(['keep', 'shared', 'health_path'], null, 'deploy');
});

it('shows a new webhook secret once and renews the key and the URL', function () {
    $this->fake->on('v-list-web-domain-deploy')->returnsJson(['example.com' => deployActive()]);
    $this->fake->on('v-list-web-domain-deploys')->returnsJson(deployHistory());
    $this->fake->on('v-change-web-domain-deploy-credential', fn ($a) => $a[2] === 'webhook')->returns("WEBHOOK_SECRET='NewSecret0123456789abcdefghijABCDEFGHIJ0'\n");
    $this->fake->on('v-change-web-domain-deploy-credential', fn ($a) => $a[2] !== 'webhook')->returns();
    signIn('bob', 'user');

    $this->post(DEPLOY_BASE.'/credential/webhook')->assertOk()->assertSee('NewSecret0123456789abcdefghijABCDEFGHIJ0');
    $this->post(DEPLOY_BASE.'/credential/key')->assertRedirect(DEPLOY_TAB.'#deploy')->assertSessionHas('ok');
    $this->post(DEPLOY_BASE.'/credential/id')->assertRedirect(DEPLOY_TAB.'#deploy');
});

it('manages the trusted publishers of push mode', function () {
    $this->fake->on('v-list-web-domain-deploy')->returnsJson(['example.com' => deployActive(['MODE' => 'push', 'URL' => '', 'BRANCH' => '', 'AUTH' => '', 'PRESET' => 'none', 'WEBHOOK_URL' => '', 'UPLOAD_URL' => 'https://panel.example.com:8088/upload/k3v9q2m7x1c8',
        'PUBLISHERS' => [['PUB' => 'p1', 'ISSUER' => 'https://token.actions.githubusercontent.com', 'SUBJECT_REPO' => 'acme/shop', 'REF' => 'refs/heads/main', 'ENVIRONMENT' => '', 'WORKFLOW' => '']]])]);
    $this->fake->on('v-list-web-domain-deploys')->returnsJson(deployHistory());
    $this->fake->on('v-list-sys-deploy')->returnsJson(['DEPLOY_PORT' => '8088', 'DEPLOY_MAX_ARTIFACT' => '512M', 'DEPLOY_BUILD_TIMEOUT' => '900', 'DEPLOY_OIDC_ISSUERS' => 'https://token.actions.githubusercontent.com,https://gitlab.com', 'DOMAINS' => '1', 'PORT_OPEN' => 'yes']);
    $this->fake->on('v-add-web-domain-deploy-publisher')->returnsJson(['ID' => 'p2']);
    $this->fake->on('v-delete-web-domain-deploy-publisher')->returns();
    signIn('bob', 'user');

    $this->get(DEPLOY_TAB)->assertOk()->assertSee('https://panel.example.com:8088/upload/k3v9q2m7x1c8')
        ->assertSee('acme/shop')->assertSee('id-token: write');
    $this->post(DEPLOY_BASE.'/publishers', ['issuer' => 'https://gitlab.com', 'repo' => 'acme/group/shop', 'ref' => '', 'environment' => 'production'])
        ->assertRedirect(DEPLOY_TAB.'#deploy');
    $this->fake->assertRan('v-add-web-domain-deploy-publisher', fn ($a) => $a === ['bob', 'example.com', 'https://gitlab.com', 'acme/group/shop', '', 'production', '', 'json']);
    $this->delete(DEPLOY_BASE.'/publishers/p1')->assertRedirect(DEPLOY_TAB.'#deploy');
    $this->fake->assertRan('v-delete-web-domain-deploy-publisher', fn ($a) => $a === ['bob', 'example.com', 'p1']);
});

it('turns deploy off', function () {
    $this->fake->on('v-delete-web-domain-deploy')->returns();
    signIn('bob', 'user');

    $this->delete(DEPLOY_BASE)->assertRedirect(DEPLOY_TAB.'#deploy')->assertSessionHas('ok', 'Git Deploy is off.');
    $this->fake->assertRan('v-delete-web-domain-deploy', fn ($a) => $a === ['bob', 'example.com']);
});

it('reports a refused command on the tab', function () {
    $this->fake->on('v-list-web-domain-deploy')->returnsJson(['example.com' => deployActive()]);
    $this->fake->on('v-schedule-web-domain-deploy')->returnsExit(ExitCode::Suspended, 'Error: example.com is suspended');
    signIn('bob', 'user');

    $this->post(DEPLOY_BASE.'/run')->assertRedirect(DEPLOY_TAB.'#deploy')->assertSessionHas('error', 'The deploy did not start.');
});

// Logs and live state

it('answers the log of a deploy task of the domain only', function () {
    $this->fake->on('v-list-user-tasks', ['bob', '17617400001234', '400', 'json'])->returnsJson([
        'ID' => '17617400001234', 'KIND' => 'deploy', 'ARGS' => 'example.com pull main', 'STATUS' => 'succeeded', 'EXIT' => '0', 'ERROR' => '',
        'QUEUED' => '2026-10-29 14:24:58', 'START' => '2026-10-29 14:24:58', 'END' => '2026-10-29 14:26:10', 'LOG' => "2026-10-29 14:24:58 fetching main\n2026-10-29 14:26:10 release 20261029-142501 is live\n",
    ]);
    $this->fake->on('v-list-user-tasks', ['bob', '17617400002222', '400', 'json'])->returnsJson([
        'ID' => '17617400002222', 'KIND' => 'backup', 'ARGS' => 'bob', 'STATUS' => 'succeeded', 'EXIT' => '0', 'ERROR' => '', 'QUEUED' => '', 'START' => '', 'END' => '', 'LOG' => 'x',
    ]);
    signIn('bob', 'user');

    $this->getJson(DEPLOY_BASE.'/tasks/17617400001234')->assertOk()
        ->assertJsonPath('task.status', 'succeeded')->assertJsonPath('lines.1', '2026-10-29 14:26:10 release 20261029-142501 is live');
    $this->getJson(DEPLOY_BASE.'/tasks/17617400002222')->assertNotFound();
});

it('answers the running deploy with its log, for the live view', function () {
    $this->fake->on('v-list-web-domain-deploy')->returnsJson(['example.com' => deployActive(['RUNNING' => '17617400007777'])]);
    $this->fake->on('v-list-web-domain-deploys')->returnsJson(deployHistory());
    $this->fake->on('v-list-user-tasks', ['bob', '17617400007777', '400', 'json'])->returnsJson([
        'ID' => '17617400007777', 'KIND' => 'deploy', 'ARGS' => 'example.com pull main', 'STATUS' => 'running', 'EXIT' => '', 'ERROR' => '',
        'QUEUED' => '2026-10-29 14:51:02', 'START' => '2026-10-29 14:51:02', 'END' => '', 'LOG' => "2026-10-29 14:51:02 fetching main\n",
    ]);
    signIn('bob', 'user');

    $this->getJson(DEPLOY_BASE.'/state')->assertOk()->assertJsonPath('running', true)->assertJsonPath('lines.0', '2026-10-29 14:51:02 fetching main');
    $this->get(DEPLOY_TAB)->assertOk()->assertSee('A deploy is running')->assertSee('apDeploy(', false);
});
