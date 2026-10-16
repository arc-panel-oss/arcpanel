<?php

// POLICY_SYSTEM_PROTECTED_ADMIN (E39 W4 spec §5, §11): a secondary
// administrator reads the objects and the account of the ROOT_USER
// (arcadmin) and changes none of them, on the pages and through API v2;
// 404 instead of 403 under POLICY_SYSTEM_HIDE_ADMIN.

beforeEach(function () {
    $this->fake = fakeArc(protectedAdminConfig());
    fakeResellers($this->fake, ['otheradmin' => ['ROLE' => 'admin']]);
    fakeWebDomains($this->fake);
});

/** @return array<string, string> */
function protectedAdminConfig(array $overrides = []): array
{
    return array_merge([
        'POLICY_SYSTEM_PROTECTED_ADMIN' => 'yes', 'API_SYSTEM' => '2', 'API_ALLOWED_IP' => 'allow-all',
        'MALWARE_SCAN' => 'yes', 'PORT_REGISTRY' => 'yes',
    ], $overrides);
}

/** Commands that change something: everything but the readers. */
function changingCommands(): array
{
    return array_values(array_filter(
        array_map(fn (array $call) => $call['command'], test()->fake->calls()),
        fn (string $command) => preg_match('/^v-(list|check|search|get-sys|log-user-log)/', $command) !== 1,
    ));
}

dataset('api changes', [
    'account' => ['putJson', '/api/v2/users/arcadmin/package', ['package' => 'default']],
    'access key' => ['postJson', '/api/v2/users/arcadmin/access-keys', ['permissions' => ['v2-read']]],
    'access key delete' => ['deleteJson', '/api/v2/users/arcadmin/access-keys/AAAAAAAAAAAAAAAAAAAA', []],
    'SSH key' => ['deleteJson', '/api/v2/users/arcadmin/ssh-keys/laptop', []],
    'secret' => ['deleteJson', '/api/v2/users/arcadmin/secrets/DB_PASSWORD', []],
    'web domain' => ['deleteJson', '/api/v2/users/arcadmin/web-domains/example.com', []],
    'web domain suspend' => ['postJson', '/api/v2/users/arcadmin/web-domains/example.com/suspend', []],
    'DNS zone' => ['deleteJson', '/api/v2/users/arcadmin/dns-zones/example.com', []],
    'mail domain' => ['deleteJson', '/api/v2/users/arcadmin/mail-domains/example.com', []],
    'database' => ['deleteJson', '/api/v2/users/arcadmin/databases/arcadmin_db', []],
    'cron job' => ['deleteJson', '/api/v2/users/arcadmin/cron-jobs/1', []],
    'backup' => ['postJson', '/api/v2/users/arcadmin/backups', []],
    'Node.js app' => ['deleteJson', '/api/v2/users/arcadmin/node-apps/example.com', []],
    'Docker app' => ['deleteJson', '/api/v2/users/arcadmin/docker-apps/web', []],
    'malware scan' => ['postJson', '/api/v2/users/arcadmin/malware/scans', []],
    'logs' => ['deleteJson', '/api/v2/users/arcadmin/logs', []],
    'ports' => ['postJson', '/api/v2/users/arcadmin/ports/reserve', ['proto' => 'tcp']],
]);

it('refuses a secondary administrator every change through API v2', function (string $method, string $uri, array $body) {
    $this->{$method}($uri, $body, apiAs($this->fake, 'otheradmin', 'admin'))->assertForbidden();

    expect(changingCommands())->toBe([]);
})->with('api changes');

it('hides the refusal as 404 under POLICY_SYSTEM_HIDE_ADMIN', function (string $method, string $uri, array $body) {
    withSysConfig($this->fake, protectedAdminConfig(['POLICY_SYSTEM_HIDE_ADMIN' => 'yes']));

    $this->{$method}($uri, $body, apiAs($this->fake, 'otheradmin', 'admin'))->assertNotFound();

    expect(changingCommands())->toBe([]);
})->with('api changes');

it('lets the root administrator and, with the policy off, other administrators make the change', function (string $method, string $uri, array $body) {
    expect($this->{$method}($uri, $body, apiAs($this->fake, 'arcadmin', 'admin'))->status())->not->toBe(403);

    withSysConfig($this->fake, protectedAdminConfig(['POLICY_SYSTEM_PROTECTED_ADMIN' => 'no']));
    expect($this->{$method}($uri, $body, apiAs($this->fake, 'otheradmin', 'admin'))->status())->not->toBe(403);
})->with('api changes');

it('lets a secondary administrator read the root administrator\'s account and objects', function () {
    $headers = apiAs($this->fake, 'otheradmin', 'admin');

    $this->getJson('/api/v2/users/arcadmin', $headers)->assertOk();
    $this->getJson('/api/v2/users/arcadmin/web-domains', $headers)->assertOk();
});

dataset('page changes', [
    'account' => ['post', '/users/arcadmin/general', ['name' => 'Root', 'email' => 'root@example.com', 'language' => 'en']],
    'package' => ['post', '/users/arcadmin/package', ['package' => 'default']],
    'access key' => ['post', '/access-keys/arcadmin', ['sets' => ['v2-read']]],
    'SSH key' => ['delete', '/ssh-keys/arcadmin/laptop', []],
    'web domain' => ['delete', '/web/domains/arcadmin/example.com', []],
    'web domain suspend' => ['post', '/web/domains/arcadmin/example.com/suspend', []],
    'database' => ['delete', '/db/arcadmin/arcadmin_db', []],
    'cron job' => ['delete', '/cron/arcadmin/1', []],
    'backup' => ['post', '/backups/arcadmin', []],
    'file' => ['post', '/files/arcadmin/api/mkdir', ['path' => '/home/arcadmin', 'name' => 'x']],
]);

it('refuses a secondary administrator every change on the pages', function (string $method, string $uri, array $body) {
    signIn('otheradmin', 'admin');

    $this->{$method}($uri, $body)->assertForbidden();

    expect(changingCommands())->toBe([]);
})->with('page changes');

it('hides the page refusal as 404 under POLICY_SYSTEM_HIDE_ADMIN', function (string $method, string $uri, array $body) {
    withSysConfig($this->fake, protectedAdminConfig(['POLICY_SYSTEM_HIDE_ADMIN' => 'yes']));
    signIn('otheradmin', 'admin');

    $this->{$method}($uri, $body)->assertNotFound();
})->with('page changes');
