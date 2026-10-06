<?php

use App\Arc\CommandResult;
use App\Arc\ExitCode;

// Config editors of the services (E7 batch 3 spec §9).

/** The props of the service-editor island on the page. */
function editorProps($response): array
{
    preg_match('/data-island="service-editor" data-props="([^"]*)"/', $response->getContent(), $match);

    return json_decode(html_entity_decode($match[1] ?? '{}'), true);
}

beforeEach(function () {
    $this->fake = fakeArc();
    fakeServerUsers($this->fake);
    $this->fake->on('v-list-sys-nginx-config')->returnsJson(['CONFIG' => ['worker_processes' => 'auto', 'config_path' => '/etc/nginx/nginx.conf']]);
    $this->fake->on('v-list-sys-php-config')->returnsJson(['CONFIG' => ['memory_limit' => '128M', 'config_path' => '/etc/php/8.4/fpm/php.ini /etc/php/8.5/fpm/php.ini']]);
    $this->fake->on('v-list-sys-pgsql-config')->returnsJson(['CONFIG' => ['pg_hba_path' => '', 'config_path' => '']]);
    $this->fake->on('v-list-sys-dovecot-config')->returnsJson(['CONFIG' => [
        'config_path' => '/etc/dovecot/dovecot.conf', 'config_path1' => '/etc/dovecot/conf.d/10-auth.conf', 'config_path2' => '',
    ]]);
    $this->fake->on('v-list-sys-vsftpd-config')->returns("/etc/vsftpd.conf\n");
    $this->fake->on('v-open-fs-config')->using(fn (array $args) => match ($args[0]) {
        // The real command exits 127 under sudo after printing the file (ServiceConfigs::openedDespiteLogError)
        '/etc/nginx/nginx.conf' => new CommandResult('v-open-fs-config', $args, 127, "worker_processes auto;\n",
            "/usr/local/arcpanel/bin/v-open-fs-config: line 60: /v-log-action: No such file or directory\n"),
        '/etc/named.conf' => new CommandResult('v-open-fs-config', $args, 127, 'partial', "Segmentation fault\n"),
        '/etc/php/8.4/fpm/php.ini' => [0, "memory_limit = 128M\n"],
        '/etc/dovecot/dovecot.conf' => [0, "protocols = imap\n"],
        '/etc/dovecot/conf.d/10-auth.conf' => [0, "auth_mechanisms = plain\n"],
        '/etc/vsftpd.conf' => [0, "listen=YES\n"],
        '/etc/crontab' => [0, "17 * * * * root cd / && run-parts --report /etc/cron.hourly\n"],
        '/var/spool/cron/crontabs/arcpanelweb' => [0, "* * * * * sudo /usr/local/arcpanel/bin/v-update-sys-queue restart\n"],
        default => [ExitCode::NotExist->value, 'Error: file does not exist'],
    });
});

it('opens an editor with its file, the basic options and the save URL', function () {
    signIn('arcadmin', 'admin');

    $props = editorProps($this->get('/server/services/nginx/config')->assertOk()->assertSee('Configure nginx'));
    expect($props['service'])->toBe('nginx')
        ->and($props['options'])->toBe('nginx')
        ->and($props['language'])->toBe('nginx')
        ->and($props['saveUrl'])->toEndWith('/server/services/nginx/config')
        ->and($props['files'])->toBe([[
            'index' => 0, 'label' => 'nginx.conf', 'path' => '/etc/nginx/nginx.conf', 'arg' => 'nginx',
            'content' => "worker_processes auto;\n", 'writable' => true,
        ]]);

    // PHP lists the php.ini of every version and opens the first
    $props = editorProps($this->get('/server/services/php/config')->assertOk());
    expect($props['files'][0]['path'])->toBe('/etc/php/8.4/fpm/php.ini')->and($props['options'])->toBe('php');
    expect(editorProps($this->get('/server/services/php-fpm/config'))['files'][0]['arg'])->toBe('php');

    // Files the server does not have are left out
    $props = editorProps($this->get('/server/services/dovecot/config')->assertOk());
    expect(array_column($props['files'], 'arg'))->toBe(['dovecot', 'dovecot-1']);
    expect(editorProps($this->get('/server/services/vsftpd/config'))['files'][0]['path'])->toBe('/etc/vsftpd.conf');
});

it('answers 404 for unknown services and services without files, and redirects the legacy page editors', function () {
    signIn('arcadmin', 'admin');

    $this->get('/server/services/memcached/config')->assertNotFound();
    $this->get('/server/services/postgresql/config')->assertNotFound();
    // any other failure of v-open-fs-config leaves the file out
    $this->get('/server/services/named/config')->assertNotFound();
    $this->get('/server/services/iptables/config')->assertRedirect('/server/firewall');
    $this->get('/server/services/web-terminal/config')->assertRedirect('/server/services');
});

it('shows the system crontab read-only', function () {
    signIn('arcadmin', 'admin');

    $props = editorProps($this->get('/server/services/cron/config')->assertOk());
    expect($props['files'][0]['writable'])->toBeFalse();
    $this->postJson('/server/services/cron/config', ['index' => 0, 'content' => 'x', 'restart' => true])->assertForbidden();
    $this->fake->assertNotRan('v-change-sys-service-config');
});

it('saves a file through a temporary file with LF line ends', function () {
    $this->fake->on('v-change-sys-service-config')->returns();
    signIn('arcadmin', 'admin');

    $this->postJson('/server/services/nginx/config', ['index' => 0, 'content' => "worker_processes 4;\r\n", 'restart' => true])
        ->assertOk()->assertJsonPath('message', 'Changes have been saved.');
    $this->fake->assertRan('v-change-sys-service-config', fn ($a) => $a === ["worker_processes 4;\n", 'nginx', 'yes']);

    $this->postJson('/server/services/dovecot/config', ['index' => 1, 'content' => 'auth_mechanisms = plain login', 'restart' => false])->assertOk();
    $this->fake->assertRan('v-change-sys-service-config', fn ($a) => $a === ['auth_mechanisms = plain login', 'dovecot-1', 'no']);

    // The panel crontab always reloads
    $this->postJson('/server/services/arcpanelweb/config', ['index' => 0, 'content' => '# empty', 'restart' => false])->assertOk();
    $this->fake->assertRan('v-change-sys-service-config', fn ($a) => $a === ['# empty', 'arcpanelweb', 'yes']);

    $this->postJson('/server/services/nginx/config', ['index' => 5, 'content' => 'x', 'restart' => true])->assertForbidden();
    $this->postJson('/server/services/nope/config', ['index' => 0, 'content' => 'x', 'restart' => true])->assertNotFound();
});

it('returns the command error when the service does not start', function () {
    $this->fake->on('v-change-sys-service-config')->returnsExit(ExitCode::Restart, 'Error: ERROR: nginx failed to start with new configuration.');
    signIn('arcadmin', 'admin');

    $this->postJson('/server/services/nginx/config', ['index' => 0, 'content' => 'broken', 'restart' => true])
        ->assertStatus(422)->assertJsonPath('error.message', 'Error: ERROR: nginx failed to start with new configuration.');
});

it('keeps the editors for administrators and read-only in read-only mode', function () {
    fakeResellers($this->fake);
    foreach ([['bob', 'user'], ['hostco', 'reseller']] as [$user, $role]) {
        signIn($user, $role);
        $this->get('/server/services/nginx/config')->assertForbidden();
        $this->postJson('/server/services/nginx/config', ['index' => 0, 'content' => 'x', 'restart' => true])->assertForbidden();
    }

    withSysConfig($this->fake, ['POLICY_SYSTEM_PROTECTED_ADMIN' => 'yes']);
    fakeServerUsers($this->fake, ['admin2' => ['ROLE' => 'admin']]);
    signIn('admin2', 'admin', ['look' => 'arcadmin']);
    expect(editorProps($this->get('/server/services/nginx/config')->assertOk())['files'][0]['writable'])->toBeFalse();
    $this->postJson('/server/services/nginx/config', ['index' => 0, 'content' => 'x', 'restart' => true])->assertForbidden();
    $this->fake->assertNotRan('v-change-sys-service-config');
});

it('links the services to their editors', function () {
    $this->fake->on('v-list-sys-info')->returnsJson(['sysinfo' => ['HOSTNAME' => 'srv1.example.net', 'UPTIME' => '10', 'LOADAVERAGE' => '0']]);
    $this->fake->on('v-list-sys-php')->returnsJson(['8.4']);
    $this->fake->on('v-list-sys-services')->returnsJson([
        'nginx' => ['SYSTEM' => 'reverse proxy', 'STATE' => 'running', 'CPU' => '0', 'MEM' => '1', 'RTIME' => '1'],
        'php8.4-fpm' => ['SYSTEM' => 'php interpreter', 'STATE' => 'running', 'CPU' => '0', 'MEM' => '1', 'RTIME' => '1'],
    ]);
    signIn('arcadmin', 'admin');

    $this->get('/server/services')->assertOk()
        ->assertSee(route('server.services.config', ['service' => 'nginx']), false)
        ->assertSee(route('server.services.config', ['service' => 'php']), false);
});
