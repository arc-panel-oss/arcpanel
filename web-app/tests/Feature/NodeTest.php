<?php

use App\Arc\ExitCode;
use App\Arc\SysConfig;

// Node.js page and its JSON routes (E13 spec §6, §7). hostco owns bob (on
// the developer package, with Node.js), resb owns carol (default package,
// without Node.js).

beforeEach(function () {
    $this->fake = fakeArc(['WEB_TERMINAL' => 'true']);
    fakeResellers($this->fake, ['bob' => ['PACKAGE' => 'developer', 'SHELL' => 'bash']]);
    $this->fake->on('v-list-package-tenancy', ['developer', 'json'])->returnsJson(['developer' => ['NODE' => 'yes', 'NODE_VERSIONS' => '3', 'NODE_ALLOWED' => 'lts']]);
    $this->fake->on('v-list-package-tenancy', ['default', 'json'])->returnsJson(['default' => ['NODE' => 'no']]);
    $this->fake->on('v-list-user-node-versions', ['bob', 'json'])->returnsJson(nodeAccount('bob', [], [
        nodeRuntime('24.21.0', ['DEFAULT' => 'yes', 'PACKAGES' => '2']),
        nodeRuntime('22.23.3', ['LTS' => 'Jod', 'STATUS' => 'maintenance']),
    ]));
    $this->fake->on('v-list-user-node-versions', ['carol', 'json'])->returnsJson(nodeAccount('carol',
        ['PACKAGE' => 'default', 'NODE' => 'no', 'ENABLED' => 'yes'], [nodeRuntime('22.23.3', ['DEFAULT' => 'yes'])]));
    foreach (['bob', 'carol'] as $user) {
        $this->fake->on('v-list-user-node-task', [$user, '100', 'json'])->returnsJson(nodeTask($user));
    }
});

it('shows a user its runtimes and mounts the island', function () {
    signIn('bob', 'user');

    $this->get('/node/bob')->assertOk()
        ->assertSee('data-island="node-manager"', false)
        ->assertSee('v24.21.0')->assertSee('v22.23.3')
        ->assertSee('href="'.route('node.index').'"', false);
});

it('sends /node to the page of the effective account', function () {
    signIn('bob', 'user');
    $this->get('/node')->assertRedirect('/node/bob');

    signIn('arcadmin', 'admin', ['look' => 'bob']);
    $this->get('/node')->assertRedirect('/node/bob');
});

it('passes the abilities and the terminal link to the island', function () {
    signIn('bob', 'user');

    $props = json_decode(html_entity_decode((string) str($this->get('/node/bob')->getContent())->match('/data-props="([^"]*)"/')), true);
    expect($props['abilities'])->toBe(['manage' => true, 'remove' => true])
        ->and($props['account']['default'])->toBe('24.21.0')
        ->and($props['account']['hasRoom'])->toBeTrue()
        ->and($props['terminal'])->not->toBeNull()
        ->and($props['base'])->toEndWith('/node/bob/api')
        ->and($props['strings'])->toHaveKey('Install a version');
});

it('hides Node.js in the sidebar when the package does not include it', function () {
    signIn('carol', 'user');

    $this->get('/node/carol')->assertOk()
        ->assertDontSee('href="'.route('node.index').'"', false)
        ->assertSee('Node.js is not included in your package.');
});

it('answers 404 outside the scope', function () {
    signIn('bob', 'user');
    $this->get('/node/carol')->assertNotFound();
    $this->postJson('/node/carol/api/versions', ['spec' => '22'])->assertNotFound();

    signIn('hostco', 'reseller');
    $this->get('/node/bob')->assertOk();
    $this->getJson('/node/carol/api/overview')->assertNotFound();

    signIn('resb', 'reseller');
    $this->postJson('/node/bob/api/versions/24.21.0/default')->assertNotFound();
    $this->getJson('/node/carol/api/overview')->assertOk();

    signIn('arcadmin', 'admin');
    $this->get('/node/carol')->assertOk();
});

it('starts installs as background tasks', function () {
    signIn('bob', 'user');
    $this->fake->on('v-start-user-node-task')->returns('17286400001234');

    $this->postJson('/node/bob/api/versions', ['spec' => '24'])->assertStatus(202)->assertJsonPath('id', '17286400001234');
    $this->fake->assertRan('v-start-user-node-task', fn ($a) => $a === ['bob', 'add-version', '24']);

    $this->postJson('/node/bob/api/versions', ['spec' => 'lts;id'])->assertUnprocessable();
    $this->postJson('/node/bob/api/versions', ['spec' => '24.1'])->assertUnprocessable();

    $this->postJson('/node/bob/api/versions/24.21.0/packages', ['spec' => '@nestjs/cli@11'])->assertStatus(202);
    $this->fake->assertRan('v-start-user-node-task', fn ($a) => $a === ['bob', 'add-package', '24.21.0', '@nestjs/cli@11']);
    foreach (['https://example.invalid/x.tgz', 'git+ssh://example.invalid/x', '../x', 'file:x', 'a b', 'UPPER'] as $spec) {
        $this->postJson('/node/bob/api/versions/24.21.0/packages', ['spec' => $spec])->assertUnprocessable();
    }

    $this->postJson('/node/bob/api/versions/24.21.0/packages/update')->assertStatus(202);
    $this->fake->assertRan('v-start-user-node-task', fn ($a) => $a === ['bob', 'update-packages', '24.21.0']);
    $this->postJson('/node/bob/api/versions/24.21.0/packages/outdated')->assertStatus(202);
    $this->fake->assertRan('v-start-user-node-task', fn ($a) => $a === ['bob', 'check-outdated', '24.21.0']);
});

it('reports a running task with the error contract', function () {
    signIn('bob', 'user');
    $this->fake->on('v-start-user-node-task')->returnsExit(ExitCode::InUse, 'Error: Another Node.js task is running for bob');

    $this->postJson('/node/bob/api/versions', ['spec' => '22'])->assertStatus(409)
        ->assertJsonPath('error.code', 'E_INUSE')
        ->assertJsonPath('error.message', 'Error: Another Node.js task is running for bob');
});

it('reads the task with its log', function () {
    signIn('bob', 'user');
    $this->fake->on('v-list-user-node-task', ['bob', '50', 'json'])->returnsJson(nodeTask('bob',
        ['ID' => '1', 'ACTION' => 'add-package', 'ARGS' => '24.21.0 pm2', 'STATUS' => 'running', 'LOG' => "Installing pm2\nadded 1 package"]));

    $this->getJson('/node/bob/api/task?lines=50')->assertOk()
        ->assertJsonPath('task.running', true)
        ->assertJsonPath('task.log', "Installing pm2\nadded 1 package");
    $this->getJson('/node/bob/api/task?lines=5000')->assertUnprocessable();
});

it('changes the default, uninstalls and enables', function () {
    signIn('bob', 'user');
    $this->fake->on('v-change-user-node-default')->returns();
    $this->fake->on('v-delete-user-node-version')->returns();
    $this->fake->on('v-add-user-nvm')->returns();
    $this->fake->on('v-delete-user-nvm')->returns();

    $this->postJson('/node/bob/api/versions/22.23.3/default')->assertOk()->assertJsonPath('account.user', 'bob');
    $this->fake->assertRan('v-change-user-node-default', fn ($a) => $a === ['bob', '22.23.3']);
    $this->deleteJson('/node/bob/api/versions/22.23.3')->assertOk();
    $this->fake->assertRan('v-delete-user-node-version', fn ($a) => $a === ['bob', '22.23.3']);
    $this->postJson('/node/bob/api/enable')->assertOk();
    $this->fake->assertRan('v-add-user-nvm', fn ($a) => $a === ['bob']);
    $this->deleteJson('/node/bob/api/enable')->assertOk();
    $this->fake->assertRan('v-delete-user-nvm', fn ($a) => $a === ['bob']);

    $this->postJson('/node/bob/api/versions/latest/default')->assertNotFound();
});

it('lists and removes global packages, scoped names too', function () {
    signIn('bob', 'user');
    $list = ['USER' => 'bob', 'VERSION' => '24.21.0', 'CHECKED' => '', 'PACKAGES' => [
        ['NAME' => '@nestjs/cli', 'VERSION' => '11.0.0', 'LATEST' => '', 'OUTDATED' => '', 'BUNDLED' => 'no'],
        ['NAME' => 'npm', 'VERSION' => '11.6.0', 'LATEST' => '', 'OUTDATED' => '', 'BUNDLED' => 'yes'],
    ]];
    $this->fake->on('v-list-user-npm-packages', ['bob', '24.21.0', 'json'])->returnsJson($list);
    $this->fake->on('v-delete-user-npm-package')->returns();

    $this->getJson('/node/bob/api/versions/24.21.0/packages')->assertOk()
        ->assertJsonPath('packages.0.name', '@nestjs/cli')->assertJsonPath('packages.1.bundled', true);
    $this->deleteJson('/node/bob/api/versions/24.21.0/packages/@nestjs/cli')->assertOk();
    $this->fake->assertRan('v-delete-user-npm-package', fn ($a) => $a === ['bob', '24.21.0', '@nestjs/cli']);
    $this->deleteJson('/node/bob/api/versions/24.21.0/packages/Bad%20Name')->assertNotFound();
});

it('lists the releases with what the package allows', function () {
    signIn('bob', 'user');
    $this->fake->on('v-list-sys-node-releases', ['json'])->returnsJson(['ARCH' => 'x64', 'RELEASES' => [
        ['MAJOR' => '26', 'LATEST' => '26.10.0', 'LTS' => '', 'STATUS' => 'current', 'EOL' => '2029-04-30', 'CACHED' => 'no'],
        ['MAJOR' => '24', 'LATEST' => '24.21.0', 'LTS' => 'Krypton', 'STATUS' => 'active', 'EOL' => '2028-04-30', 'CACHED' => 'yes'],
        ['MAJOR' => '20', 'LATEST' => '20.20.2', 'LTS' => 'Iron', 'STATUS' => 'eol', 'EOL' => '2026-04-30', 'CACHED' => 'no'],
    ]]);

    $this->getJson('/node/bob/api/releases')->assertOk()
        ->assertJsonPath('releases.0.allowed', false)
        ->assertJsonPath('releases.1.allowed', true)
        ->assertJsonPath('releases.1.cached', true)
        ->assertJsonPath('releases.2.allowed', false);
});

it('lets an account without Node.js remove but not install', function () {
    signIn('carol', 'user');
    $this->fake->on('v-delete-user-node-version')->returns();

    $this->postJson('/node/carol/api/enable')->assertForbidden();
    $this->postJson('/node/carol/api/versions', ['spec' => '22'])->assertForbidden();
    $this->postJson('/node/carol/api/versions/22.23.3/default')->assertForbidden();
    $this->deleteJson('/node/carol/api/versions/22.23.3')->assertOk();
});

it('refuses every change in read-only mode', function () {
    $this->fake->on('v-list-sys-config', ['json'])->returnsJson(sysConfig(['POLICY_USER_VIEW_SUSPENDED' => 'yes', 'WEB_TERMINAL' => 'true']));
    cache()->flush();
    app()->forgetInstance(SysConfig::class);
    fakeResellers($this->fake, ['bob' => ['PACKAGE' => 'developer', 'SUSPENDED' => 'yes']]);
    signIn('bob', 'user');

    $this->get('/node/bob')->assertOk();
    $this->postJson('/node/bob/api/versions', ['spec' => '22'])->assertForbidden();
    $this->postJson('/node/bob/api/enable')->assertForbidden();
    $this->deleteJson('/node/bob/api/versions/22.23.3')->assertForbidden();
});
