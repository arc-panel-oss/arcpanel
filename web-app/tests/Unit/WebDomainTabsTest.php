<?php

use App\Arc\Secret;
use App\Services\WebDomains\DomainProbe;
use App\Services\WebDomains\Step;
use App\Services\WebDomains\Tabs\AdvancedTab;
use App\Services\WebDomains\Tabs\FtpTab;
use App\Services\WebDomains\Tabs\GeneralTab;
use App\Services\WebDomains\Tabs\RedirectTab;
use App\Services\WebDomains\Tabs\SslTab;
use App\Services\WebDomains\Tabs\StatsTab;
use App\Services\WebDomains\WebDomainRecord;
use App\Services\WebDomains\WebDomainSsl;

/** example.com of bob: Let's Encrypt, force SSL, awstats, one FTP account. */
function exampleDomain(array $overrides = []): WebDomainRecord
{
    $row = array_merge(commandFixture('v-list-web-domain/bob-example.com.json')['example.com'], $overrides);

    return WebDomainRecord::fromArray('bob', 'example.com', $row);
}

/** shop.example of bob: no SSL, redirect to www, custom document root, suspended. */
function shopDomain(array $overrides = []): WebDomainRecord
{
    $row = array_merge(commandFixture('v-list-web-domain/bob-shop.example.json')['shop.example'], $overrides);

    return WebDomainRecord::fromArray('bob', 'shop.example', $row);
}

/** @param  list<string>  $dnsZones  domains with a DNS zone */
function probe(array $dnsZones = [], array $mailDomains = []): DomainProbe
{
    return new class($dnsZones, $mailDomains) implements DomainProbe
    {
        public function __construct(private array $dns, private array $mail) {}

        public function dnsDomainExists(string $user, string $domain): bool
        {
            return in_array($domain, $this->dns, true);
        }

        public function mailDomainExists(string $user, string $domain): bool
        {
            return in_array($domain, $this->mail, true);
        }
    };
}

/**
 * Steps as "command arg arg …" lines; secrets show their revealed value.
 *
 * @param  list<Step>  $steps
 * @return list<string>
 */
function commands(array $steps): array
{
    return array_map(
        fn (Step $step) => trim($step->command.' '.implode(' ', array_map(
            fn ($arg) => $arg instanceof Secret ? '<'.$arg->reveal().'>' : (string) $arg,
            $step->args,
        ))),
        $steps,
    );
}

$ips = ['10.0.0.10' => ['NAT' => '203.0.113.10'], '10.0.0.20' => ['NAT' => '']];

it('parses a web domain record', function () {
    $domain = exampleDomain();

    expect($domain->aliases)->toBe(['www.example.com', 'blog.example.com'])
        ->and($domain->extraAliases())->toBe(['blog.example.com'])
        ->and($domain->ssl && $domain->letsEncrypt && $domain->sslForce)->toBeTrue()
        ->and($domain->sslHsts)->toBeFalse()
        ->and($domain->ftpAccounts)->toBe([['user' => 'bob_deploy', 'path' => 'releases']])
        ->and($domain->proxyExtensions)->toBe(['css', 'js', 'png', 'jpg'])
        ->and(shopDomain()->customDocrootParts())->toBe(['domain' => 'example.com', 'folder' => 'shop'])
        ->and(shopDomain()->sslKeyAlgo)->toBe('ecdsa-256');
});

// General

it('plans nothing when the general tab did not change', function () use ($ips) {
    $steps = (new GeneralTab)->plan(exampleDomain(), [
        'aliases' => "www.example.com\nblog.example.com", 'ip' => '10.0.0.10', 'docroot_custom' => false,
    ], $ips, probe(['example.com']));

    expect($steps)->toBe([]);
});

it('changes the IP and the DNS zones and mail domain that follow it', function () use ($ips) {
    $steps = (new GeneralTab)->plan(exampleDomain(), [
        'aliases' => 'www.example.com, blog.example.com', 'ip' => '10.0.0.20',
    ], $ips, probe(['example.com', 'blog.example.com'], ['example.com']));

    expect(commands($steps))->toBe([
        'v-change-web-domain-ip bob example.com 10.0.0.20 no',
        'v-change-dns-domain-ip bob example.com 10.0.0.20 no',
        'v-change-dns-domain-ip bob blog.example.com 10.0.0.20',
        'v-rebuild-mail-domain bob example.com',
    ])->and($steps[0]->restart)->toBe(['web', 'proxy']);
});

it('adds and deletes aliases with DNS records, renews Let’s Encrypt and the statistics', function () use ($ips) {
    $steps = (new GeneralTab)->plan(exampleDomain(), [
        'aliases' => "www.example.com\nshop.example.com", 'ip' => '10.0.0.10',
    ], $ips, probe(['example.com']));

    expect(commands($steps))->toBe([
        'v-delete-web-domain-alias bob example.com blog.example.com no',
        'v-delete-dns-on-web-alias bob example.com blog.example.com no',
        'v-add-web-domain-alias bob example.com shop.example.com no',
        'v-add-dns-on-web-alias bob shop.example.com 203.0.113.10 no',
        'v-add-letsencrypt-domain bob example.com www.example.com,shop.example.com  ecdsa-256',
        'v-change-web-domain-stats bob example.com awstats',
    ]);
});

it('skips the DNS records of aliases without a DNS zone', function () use ($ips) {
    $steps = (new GeneralTab)->plan(shopDomain(), ['aliases' => '', 'ip' => '10.0.0.10', 'docroot_custom' => true, 'docroot_domain' => 'example.com', 'docroot_folder' => 'shop'], $ips, probe());

    expect(commands($steps))->toBe(['v-delete-web-domain-alias bob shop.example www.shop.example no']);
});

it('changes and resets the custom document root', function () use ($ips) {
    $tab = new GeneralTab;
    $base = ['aliases' => 'www.shop.example', 'ip' => '10.0.0.10'];

    expect(commands($tab->plan(shopDomain(), $base + ['docroot_custom' => false], $ips, probe())))
        ->toBe(['v-change-web-domain-docroot bob shop.example default']);
    expect(commands($tab->plan(shopDomain(), $base + ['docroot_custom' => true, 'docroot_domain' => 'example.com', 'docroot_folder' => '/store/'], $ips, probe())))
        ->toBe(['v-change-web-domain-docroot bob shop.example example.com store yes']);
    expect(commands($tab->plan(shopDomain(), $base + ['docroot_custom' => true, 'docroot_domain' => 'shop.example', 'docroot_folder' => ''], $ips, probe())))
        ->toBe(['v-change-web-domain-docroot bob shop.example default']);
});

// SSL

it('plans nothing when the SSL tab did not change', function () {
    $steps = (new SslTab)->plan(exampleDomain(), null, [
        'ssl' => true, 'letsencrypt' => true, 'key_algo' => 'ecdsa-256', 'force' => true, 'hsts' => false,
    ]);

    expect($steps)->toBe([]);
});

it('turns SSL off: Let’s Encrypt goes, and with it the certificate and forced HTTPS', function () {
    $steps = (new SslTab)->plan(exampleDomain(), null, ['ssl' => false, 'letsencrypt' => true]);

    // web/edit/web then also drops the forced HTTPS redirection that was on.
    expect(commands($steps))->toBe([
        'v-delete-letsencrypt-domain bob example.com',
        'v-delete-web-domain-ssl-force bob example.com',
    ]);
});

it('reissues the Let’s Encrypt certificate with another key type and toggles HSTS and HTTPS redirection', function () {
    $steps = (new SslTab)->plan(exampleDomain(), null, [
        'ssl' => true, 'letsencrypt' => true, 'key_algo' => 'rsa', 'force' => false, 'hsts' => true,
    ]);

    expect(commands($steps))->toBe([
        'v-add-letsencrypt-domain bob example.com www.example.com,blog.example.com  rsa',
        'v-add-web-domain-ssl-hsts bob example.com',
        'v-delete-web-domain-ssl-force bob example.com',
    ]);
});

it('installs an uploaded certificate through a secret directory', function () {
    $steps = (new SslTab)->plan(shopDomain(), null, [
        'ssl' => true, 'letsencrypt' => false, 'certificate' => "CERT\r\nBODY", 'key' => 'KEY', 'ca' => '', 'force' => true,
    ]);

    expect(commands($steps))->toBe([
        'v-add-web-domain-ssl bob shop.example <{"shop.example.crt":"CERT\nBODY","shop.example.key":"KEY"}> same no',
        'v-add-web-domain-ssl-force bob shop.example',
    ]);
});

it('issues a Let’s Encrypt certificate for a domain without SSL', function () {
    $steps = (new SslTab)->plan(shopDomain(), null, ['ssl' => true, 'letsencrypt' => true, 'key_algo' => 'ecdsa-384']);

    expect(commands($steps))->toBe(['v-add-letsencrypt-domain bob shop.example www.shop.example  ecdsa-384']);
});

it('replaces an uploaded certificate and keeps the stored key when none is given', function () {
    $domain = exampleDomain(['LETSENCRYPT' => 'no']);
    $current = WebDomainSsl::fromArray(['CRT' => 'OLD', 'KEY' => 'STOREDKEY', 'CA' => '']);

    $steps = (new SslTab)->plan($domain, $current, ['ssl' => true, 'letsencrypt' => false, 'certificate' => 'NEW', 'key' => '', 'force' => true, 'key_algo' => 'ecdsa-256']);
    expect(commands($steps))->toBe([
        'v-change-web-domain-sslcert bob example.com <{"example.com.crt":"NEW\\n","example.com.key":"STOREDKEY\\n"}> no',
    ]);

    $same = (new SslTab)->plan($domain, $current, ['ssl' => true, 'letsencrypt' => false, 'certificate' => 'OLD', 'key' => '', 'force' => true]);
    expect($same)->toBe([]);
});

it('disables SSL of an uploaded certificate', function () {
    $steps = (new SslTab)->plan(exampleDomain(['LETSENCRYPT' => 'no']), null, ['ssl' => false]);

    expect(commands($steps))->toBe(['v-delete-web-domain-ssl bob example.com no']);
});

// Redirect

it('adds, changes and deletes the redirect', function () {
    $tab = new RedirectTab;

    expect(commands($tab->plan(exampleDomain(), ['enabled' => true, 'target' => 'bare', 'code' => 302])))
        ->toBe(['v-add-web-domain-redirect bob example.com example.com 302']);
    expect($tab->plan(shopDomain(), ['enabled' => true, 'target' => 'www', 'code' => 301]))->toBe([]);
    expect(commands($tab->plan(shopDomain(), ['enabled' => true, 'target' => 'custom', 'custom' => 'https://shop.example.net/', 'code' => 301])))
        ->toBe(['v-add-web-domain-redirect bob shop.example https://shop.example.net/ 301']);
    expect(commands($tab->plan(shopDomain(), ['enabled' => false])))->toBe(['v-delete-web-domain-redirect bob shop.example']);
    expect($tab->plan(exampleDomain(), ['enabled' => false]))->toBe([]);
    expect(RedirectTab::choice(shopDomain()))->toBe('www');
});

// Advanced

it('changes templates, proxy and FastCGI cache in the legacy order', function () {
    $systems = ['backend' => true, 'proxy' => true, 'nginx' => true];
    $steps = (new AdvancedTab)->plan(exampleDomain(), [
        'template' => 'wordpress', 'backend_template' => 'PHP-8_5', 'proxy' => true, 'proxy_template' => 'caching',
        'proxy_extensions' => "css, js\npng jpg webp", 'fastcgi_cache' => true, 'fastcgi_duration' => '',
    ], $systems);

    expect(commands($steps))->toBe([
        'v-change-web-domain-tpl bob example.com wordpress no',
        'v-change-web-domain-backend-tpl bob example.com PHP-8_5',
        'v-add-fastcgi-cache bob example.com 2m',
        'v-change-web-domain-proxy-tpl bob example.com caching css,js,png,jpg,webp no',
    ]);
});

it('plans nothing when the advanced tab did not change, and turns the proxy off', function () {
    $systems = ['backend' => true, 'proxy' => true, 'nginx' => false];
    $same = ['template' => 'default', 'backend_template' => 'default', 'proxy' => true, 'proxy_template' => 'default', 'proxy_extensions' => 'css,js,png,jpg'];

    expect((new AdvancedTab)->plan(exampleDomain(), $same, $systems))->toBe([])
        ->and(commands((new AdvancedTab)->plan(exampleDomain(), ['proxy' => false] + $same, $systems)))
        ->toBe(['v-delete-web-domain-proxy bob example.com no']);
});

it('ignores the backend and the cache when the server has none', function () {
    $steps = (new AdvancedTab)->plan(exampleDomain(), ['backend_template' => 'PHP-8_5', 'fastcgi_cache' => true, 'proxy' => true, 'proxy_extensions' => 'css,js,png,jpg'], ['backend' => false, 'proxy' => true, 'nginx' => false]);

    expect($steps)->toBe([]);
});

// FTP

it('adds, changes and deletes FTP accounts with secret passwords and mails credentials', function () {
    $mails = [];
    $mail = function (string $to, string $user, string $password) use (&$mails) {
        $mails[] = "{$to} {$user} {$password}";
    };
    $steps = (new FtpTab)->plan(exampleDomain(), [
        'accounts' => [['user' => 'bob_deploy', 'path' => 'releases/current', 'password' => 'n3w-pass', 'email' => 'ops@example.com', 'delete' => false]],
        'new' => ['user' => 'bob_backup', 'password' => 'b4ckup', 'path' => 'backup', 'email' => ''],
    ], $mail);

    expect(commands($steps))->toBe([
        'v-change-web-domain-ftp-path bob example.com bob_deploy releases/current',
        'v-change-web-domain-ftp-password bob example.com bob_deploy <n3w-pass>',
        'v-add-web-domain-ftp bob example.com backup <b4ckup> backup',
    ]);

    ($steps[1]->onSuccess)();
    expect($mails)->toBe(['ops@example.com bob_deploy n3w-pass'])->and($steps[2]->onSuccess)->toBeNull();
});

it('deletes an FTP account and ignores accounts the domain does not have', function () {
    $steps = (new FtpTab)->plan(exampleDomain(), [
        'accounts' => [['user' => 'bob_deploy', 'delete' => true], ['user' => 'carol_x', 'path' => 'x']],
    ], fn () => null);

    expect(commands($steps))->toBe(['v-delete-web-domain-ftp bob example.com bob_deploy']);
});

// Statistics

it('changes, disables and protects the statistics', function () {
    $tab = new StatsTab;

    expect($tab->plan(exampleDomain(), ['type' => 'awstats', 'auth' => false]))->toBe([])
        ->and(commands($tab->plan(exampleDomain(), ['type' => 'none'])))->toBe(['v-delete-web-domain-stats bob example.com'])
        ->and(commands($tab->plan(shopDomain(), ['type' => 'awstats', 'auth' => true, 'user' => 'stats', 'password' => 's3cret'])))->toBe([
            'v-add-web-domain-stats bob shop.example awstats',
            'v-add-web-domain-stats-user bob shop.example stats <s3cret>',
        ])
        ->and(commands($tab->plan(exampleDomain(['STATS_USER' => 'stats']), ['type' => 'awstats', 'auth' => false])))
        ->toBe(['v-delete-web-domain-stats-user bob example.com'])
        ->and($tab->plan(exampleDomain(['STATS_USER' => 'stats']), ['type' => 'awstats', 'auth' => true, 'user' => 'stats', 'password' => '']))->toBe([]);
});
