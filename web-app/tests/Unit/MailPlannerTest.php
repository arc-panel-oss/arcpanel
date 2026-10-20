<?php

use App\Arc\Secret;
use App\Services\MailDomains\MailAccountPlanner;
use App\Services\MailDomains\MailAccountRecord;
use App\Services\MailDomains\MailDomainPlanner;
use App\Services\MailDomains\MailDomainRecord;
use App\Services\MailDomains\MailDomainSsl;
use App\Services\WebDomains\Step;

beforeEach(function () {
    fakeArc();
    $this->domains = new MailDomainPlanner;
    $this->accounts = new MailAccountPlanner;
    $this->all = ['antispam' => true, 'antivirus' => true, 'webmail' => true];
});

/** Commands and arguments of a plan; secrets as "secret:VALUE". @param  list<Step>  $steps */
function mailPlan(array $steps): array
{
    return array_map(fn (Step $step) => [$step->command, array_map(
        fn ($arg) => $arg instanceof Secret ? 'secret:'.$arg->reveal() : $arg,
        $step->args,
    )], $steps);
}

/** @param  array<string, string>  $overrides */
function mailDomain(array $overrides = []): MailDomainRecord
{
    return MailDomainRecord::fromArray('bob', 'example.com', array_merge([
        'ANTIVIRUS' => 'no', 'ANTISPAM' => 'yes', 'REJECT' => 'no', 'RATE_LIMIT' => '200', 'DKIM' => 'yes', 'CATCHALL' => '',
        'ACCOUNTS' => '1', 'U_DISK' => '0', 'SSL' => 'no', 'SSL_KEY_ALGO' => '', 'LETSENCRYPT' => 'no', 'SUSPENDED' => 'no',
        'WEBMAIL_ALIAS' => 'webmail.example.com', 'WEBMAIL' => 'roundcube',
        'U_SMTP_RELAY' => '', 'U_SMTP_RELAY_HOST' => '', 'U_SMTP_RELAY_PORT' => '', 'U_SMTP_RELAY_USERNAME' => '',
    ], $overrides));
}

function mailGeneral(array $overrides = []): array
{
    return array_merge(['webmail' => 'roundcube', 'catchall' => '', 'rate' => null, 'antispam' => true, 'reject' => false, 'antivirus' => false, 'dkim' => true], $overrides);
}

function mailSslInput(array $overrides = []): array
{
    return array_merge(['ssl' => false, 'letsencrypt' => false, 'key_algo' => 'ecdsa-256', 'certificate' => '', 'key' => '', 'ca' => ''], $overrides);
}

function mailCert(string $crt = 'CRT', string $key = 'KEY', string $ca = ''): MailDomainSsl
{
    return MailDomainSsl::fromArray(['CRT' => $crt, 'KEY' => $key, 'CA' => $ca]);
}

function mailNewDomain(array $overrides = []): array
{
    return array_merge([
        'domain' => 'new.example', 'antispam' => true, 'antivirus' => true, 'dkim' => true, 'reject' => true, 'webmail' => 'roundcube',
        'relay' => false, 'relay_host' => '', 'relay_port' => '', 'relay_user' => '', 'relay_password' => '',
    ], $overrides);
}

/** @param  array<string, string>  $overrides */
function mailAccount(array $overrides = []): MailAccountRecord
{
    return MailAccountRecord::fromArray('bob', 'example.com', 'info', array_merge([
        'ALIAS' => 'sales,hello', 'FWD' => 'a@example.net,b@example.net', 'FWD_ONLY' => '', 'AUTOREPLY' => 'no',
        'QUOTA' => '100', 'RATE_LIMIT' => '', 'U_DISK' => '0', 'SUSPENDED' => 'no',
    ], $overrides));
}

// Records

it('reads the webmail alias of the list and of the single domain as the bare alias', function () {
    expect(mailDomain()->webmailAlias)->toBe('webmail')
        ->and(mailDomain(['WEBMAIL_ALIAS' => 'mail-web'])->webmailAlias)->toBe('mail-web')
        // http:// while the mail domain has no SSL, the port of the panel host when given (E46)
        ->and(mailDomain()->webmailUrl('webmail'))->toBe('http://webmail.example.com/')
        ->and(mailDomain(['SSL' => 'yes'])->webmailUrl('webmail'))->toBe('https://webmail.example.com/')
        ->and(mailDomain()->webmailUrl('webmail', 'https://srv1.example.net:8086/'))->toBe('https://srv1.example.net:8086/')
        ->and(mailDomain(['WEBMAIL' => ''])->webmailUrl('webmail', 'https://srv1.example.net:8086/'))->toBeNull()
        ->and(mailDomain(['WEBMAIL' => ''])->webmailUrl('webmail'))->toBeNull()
        ->and(mailDomain(['WEBMAIL' => 'disabled'])->webmail)->toBe('');
});

it('starts the key algorithm from the stored one, else rsa with SSL and ecdsa-256 without', function () {
    expect(mailDomain(['SSL_KEY_ALGO' => 'ECDSA-384'])->keyAlgo())->toBe('ecdsa-384')
        ->and(mailDomain(['SSL' => 'yes'])->keyAlgo())->toBe('rsa')
        ->and(mailDomain(['SSL_KEY_ALGO' => 'bogus'])->keyAlgo())->toBe('ecdsa-256');
});

it('reads aliases, forwards, discard-all and quotas of an account', function () {
    $account = mailAccount(['FWD' => ':blackhole:', 'QUOTA' => 'unlimited']);

    expect(mailAccount()->aliases)->toBe(['sales', 'hello'])
        ->and(mailAccount()->discards())->toBeFalse()
        ->and($account->discards())->toBeTrue()
        ->and($account->forwardAddresses())->toBe([])
        ->and($account->unlimitedQuota())->toBeTrue()
        ->and(mailAccount()->email())->toBe('info@example.com');
});

// Add domain (web/add/mail)

it('adds a domain, then reject spam and the webmail client', function () {
    expect(mailPlan($this->domains->planCreate('bob', mailNewDomain(), $this->all)))->toBe([
        ['v-add-mail-domain', ['bob', 'new.example', 'yes', 'yes', 'yes']],
        ['v-add-mail-domain-reject', ['bob', 'new.example']],
        ['v-add-mail-domain-webmail', ['bob', 'new.example', 'roundcube', 'yes']],
    ]);
});

it('adds no reject spam without the spam filter and deletes webmail for "Disabled"', function () {
    $steps = $this->domains->planCreate('bob', mailNewDomain(['antispam' => false, 'webmail' => 'disabled']), $this->all);

    expect(mailPlan($steps))->toBe([
        ['v-add-mail-domain', ['bob', 'new.example', 'no', 'yes', 'yes']],
        ['v-delete-mail-domain-webmail', ['bob', 'new.example', 'yes']],
    ]);
});

it('ignores spam filter, anti-virus and webmail when the server has none', function () {
    $steps = $this->domains->planCreate('bob', mailNewDomain(), ['antispam' => false, 'antivirus' => false, 'webmail' => false]);

    expect(mailPlan($steps))->toBe([['v-add-mail-domain', ['bob', 'new.example', 'no', 'no', 'yes']]]);
});

it('adds the SMTP relay with the password in a file and port 587 by default', function () {
    $steps = $this->domains->planCreate('bob', mailNewDomain([
        'reject' => false, 'relay' => true, 'relay_host' => 'smtp.relay.example', 'relay_user' => 'me', 'relay_password' => 'p4ss',
    ]), ['antispam' => false, 'antivirus' => false, 'webmail' => false]);

    expect(mailPlan($steps))->toBe([
        ['v-add-mail-domain', ['bob', 'new.example', 'no', 'no', 'yes']],
        ['v-add-mail-domain-smtp-relay', ['bob', 'new.example', 'smtp.relay.example', 'me', 'secret:p4ss', '587']],
    ])->and($steps[1]->args[4]->viaFile)->toBeTrue();
});

it('adds no relay without a host', function () {
    $steps = $this->domains->planCreate('bob', mailNewDomain(['relay' => true]), ['antispam' => false, 'antivirus' => false, 'webmail' => false]);

    expect(array_column(mailPlan($steps), 0))->toBe(['v-add-mail-domain']);
});

// General tab (web/edit/mail)

it('changes nothing for an unchanged general tab', function () {
    expect($this->domains->planGeneral(mailDomain(), mailGeneral(), $this->all))->toBe([]);
});

it('runs the general steps in the legacy order', function () {
    $current = mailDomain(['CATCHALL' => 'old@example.net', 'REJECT' => 'no']);
    $steps = $this->domains->planGeneral($current, mailGeneral([
        'antispam' => true, 'antivirus' => true, 'dkim' => false, 'rate' => '50', 'reject' => true,
        'catchall' => 'new@example.net', 'webmail' => 'snappymail',
    ]), $this->all);

    expect(mailPlan($steps))->toBe([
        ['v-add-mail-domain-antivirus', ['bob', 'example.com']],
        ['v-delete-mail-domain-dkim', ['bob', 'example.com']],
        ['v-change-mail-domain-rate-limit', ['bob', 'example.com', '50']],
        ['v-add-mail-domain-reject', ['bob', 'example.com']],
        ['v-change-mail-domain-catchall', ['bob', 'example.com', 'new@example.net']],
        ['v-add-mail-domain-webmail', ['bob', 'example.com', 'snappymail', 'yes']],
    ]);
});

it('turns the spam filter, anti-virus and DKIM on and off', function () {
    $off = $this->domains->planGeneral(mailDomain(['ANTIVIRUS' => 'yes']), mailGeneral(['antispam' => false, 'antivirus' => false]), $this->all);
    $on = $this->domains->planGeneral(mailDomain(['ANTISPAM' => 'no', 'DKIM' => 'no']), mailGeneral(['antispam' => true, 'dkim' => true]), $this->all);

    expect(array_column(mailPlan($off), 0))->toBe(['v-delete-mail-domain-antispam', 'v-delete-mail-domain-antivirus'])
        ->and(array_column(mailPlan($on), 0))->toBe(['v-add-mail-domain-antispam', 'v-add-mail-domain-dkim']);
});

it('leaves spam filter, reject and anti-virus alone when the server has none', function () {
    $current = mailDomain(['ANTISPAM' => 'yes', 'REJECT' => 'yes', 'ANTIVIRUS' => 'yes']);
    $steps = $this->domains->planGeneral($current, mailGeneral(['antispam' => false, 'reject' => false, 'antivirus' => false]),
        ['antispam' => false, 'antivirus' => false, 'webmail' => true]);

    expect($steps)->toBe([]);
});

it('deletes the catch-all address when the field is emptied, and adds one', function () {
    $delete = $this->domains->planGeneral(mailDomain(['CATCHALL' => 'old@example.net']), mailGeneral(), $this->all);
    $add = $this->domains->planGeneral(mailDomain(), mailGeneral(['catchall' => 'all@example.net']), $this->all);

    expect(mailPlan($delete))->toBe([['v-delete-mail-domain-catchall', ['bob', 'example.com']]])
        ->and(mailPlan($add))->toBe([['v-add-mail-domain-catchall', ['bob', 'example.com', 'all@example.net']]]);
});

it('resets the rate limit to the system value when emptied, and skips it when not editable', function () {
    $reset = $this->domains->planGeneral(mailDomain(), mailGeneral(['rate' => '']), $this->all);
    $locked = $this->domains->planGeneral(mailDomain(), mailGeneral(['rate' => null]), $this->all);

    expect(mailPlan($reset))->toBe([['v-change-mail-domain-rate-limit', ['bob', 'example.com', 'system']]])
        ->and($locked)->toBe([]);
});

it('adds reject spam only with the spam filter on, and deletes it when unticked', function () {
    $withoutFilter = $this->domains->planGeneral(mailDomain(['ANTISPAM' => 'no']), mailGeneral(['antispam' => false, 'reject' => true]), $this->all);
    $afterEnabling = $this->domains->planGeneral(mailDomain(['ANTISPAM' => 'no']), mailGeneral(['antispam' => true, 'reject' => true]), $this->all);
    $delete = $this->domains->planGeneral(mailDomain(['REJECT' => 'yes']), mailGeneral(['reject' => false]), $this->all);

    expect($withoutFilter)->toBe([])
        ->and(array_column(mailPlan($afterEnabling), 0))->toBe(['v-add-mail-domain-antispam', 'v-add-mail-domain-reject'])
        ->and(mailPlan($delete))->toBe([['v-delete-mail-domain-reject', ['bob', 'example.com']]]);
});

it('runs the webmail command only when the client changed; "Disabled" deletes it (spec §11)', function () {
    $same = $this->domains->planGeneral(mailDomain(), mailGeneral(['webmail' => 'roundcube']), $this->all);
    $disable = $this->domains->planGeneral(mailDomain(), mailGeneral(['webmail' => 'disabled']), $this->all);
    $stillOff = $this->domains->planGeneral(mailDomain(['WEBMAIL' => '']), mailGeneral(['webmail' => 'disabled']), $this->all);
    $enable = $this->domains->planGeneral(mailDomain(['WEBMAIL' => '']), mailGeneral(['webmail' => 'roundcube']), $this->all);
    $noSystem = $this->domains->planGeneral(mailDomain(), mailGeneral(['webmail' => 'disabled']), ['antispam' => true, 'antivirus' => true, 'webmail' => false]);

    expect($same)->toBe([])->and($stillOff)->toBe([])->and($noSystem)->toBe([])
        ->and(mailPlan($disable))->toBe([['v-delete-mail-domain-webmail', ['bob', 'example.com', 'yes']]])
        ->and(mailPlan($enable))->toBe([['v-add-mail-domain-webmail', ['bob', 'example.com', 'roundcube', 'yes']]]);
});

// SSL tab

it('replaces an uploaded certificate when the text changed, keeping the key when it is empty', function () {
    $steps = $this->domains->planSsl(mailDomain(['SSL' => 'yes']), mailCert(), mailSslInput(['ssl' => true, 'certificate' => "NEW\r\nCRT"]));

    expect(mailPlan($steps))->toBe([[
        'v-change-mail-domain-sslcert',
        ['bob', 'example.com', 'secret:'.json_encode(['example.com.crt' => "NEW\nCRT\n", 'example.com.key' => "KEY\n"]), 'no'],
    ]]);
});

it('does not replace an unchanged certificate', function () {
    $steps = $this->domains->planSsl(mailDomain(['SSL' => 'yes']), mailCert('CRT', 'KEY', 'CA'), mailSslInput(['ssl' => true, 'certificate' => 'CRT', 'ca' => 'CA']));

    expect($steps)->toBe([]);
});

it('reissues Let\'s Encrypt when the key algorithm changed', function () {
    $current = mailDomain(['SSL' => 'yes', 'LETSENCRYPT' => 'yes', 'SSL_KEY_ALGO' => 'ecdsa-256']);
    $same = $this->domains->planSsl($current, mailCert(), mailSslInput(['ssl' => true, 'letsencrypt' => true, 'key_algo' => 'ecdsa-256']));
    $changed = $this->domains->planSsl($current, mailCert(), mailSslInput(['ssl' => true, 'letsencrypt' => true, 'key_algo' => 'rsa']));
    // An empty SSL_KEY_ALGO with SSL counts as rsa
    $legacy = $this->domains->planSsl(mailDomain(['SSL' => 'yes', 'LETSENCRYPT' => 'yes']), mailCert(),
        mailSslInput(['ssl' => true, 'letsencrypt' => true, 'key_algo' => 'rsa']));

    expect($same)->toBe([])->and($legacy)->toBe([])
        ->and(mailPlan($changed))->toBe([['v-add-letsencrypt-domain', ['bob', 'example.com', '', 'yes', 'rsa']]]);
});

it('deletes Let\'s Encrypt when it or SSL is switched off, and installs nothing in the same save', function () {
    $current = mailDomain(['SSL' => 'yes', 'LETSENCRYPT' => 'yes']);
    $leOff = $this->domains->planSsl($current, mailCert(), mailSslInput(['ssl' => true, 'letsencrypt' => false, 'certificate' => 'MINE', 'key' => 'MYKEY']));
    $sslOff = $this->domains->planSsl($current, mailCert(), mailSslInput(['ssl' => false, 'letsencrypt' => true]));

    expect(mailPlan($leOff))->toBe([['v-delete-letsencrypt-domain', ['bob', 'example.com', '', 'yes']]])
        ->and(mailPlan($sslOff))->toBe([['v-delete-letsencrypt-domain', ['bob', 'example.com', '', 'yes']]]);
});

it('deletes an uploaded certificate when SSL is switched off', function () {
    $steps = $this->domains->planSsl(mailDomain(['SSL' => 'yes']), mailCert(), mailSslInput(['ssl' => false]));

    expect(mailPlan($steps))->toBe([['v-delete-mail-domain-ssl', ['bob', 'example.com']]]);
});

it('issues Let\'s Encrypt with the chosen key algorithm', function () {
    $steps = $this->domains->planSsl(mailDomain(), null, mailSslInput(['ssl' => true, 'letsencrypt' => true, 'key_algo' => 'ecdsa-384']));

    expect(mailPlan($steps))->toBe([['v-add-letsencrypt-domain', ['bob', 'example.com', '', 'yes', 'ecdsa-384']]]);
});

it('installs an uploaded certificate without trailing newlines', function () {
    $steps = $this->domains->planSsl(mailDomain(), null, mailSslInput(['ssl' => true, 'certificate' => 'CRT', 'key' => 'KEY', 'ca' => 'CA']));

    expect(mailPlan($steps))->toBe([[
        'v-add-mail-domain-ssl',
        ['bob', 'example.com', 'secret:'.json_encode(['example.com.crt' => 'CRT', 'example.com.key' => 'KEY', 'example.com.ca' => 'CA']), 'no'],
    ]])->and(MailDomainPlanner::installsCertificate(mailDomain(), ['ssl' => true, 'letsencrypt' => false]))->toBeTrue()
        ->and(MailDomainPlanner::installsCertificate(mailDomain(['SSL' => 'yes']), ['ssl' => true, 'letsencrypt' => false]))->toBeFalse();
});

it('does nothing when SSL stays off', function () {
    expect($this->domains->planSsl(mailDomain(), null, mailSslInput()))->toBe([]);
});

// SMTP relay tab

it('adds the relay again when host, user or port changed or a password was given', function () {
    $current = mailDomain(['U_SMTP_RELAY' => 'true', 'U_SMTP_RELAY_HOST' => 'smtp.relay.example', 'U_SMTP_RELAY_PORT' => '587', 'U_SMTP_RELAY_USERNAME' => 'me']);
    $relay = fn (array $overrides) => array_merge(['relay' => true, 'relay_host' => 'smtp.relay.example', 'relay_port' => '587', 'relay_user' => 'me', 'relay_password' => ''], $overrides);

    expect($this->domains->planRelay($current, $relay([])))->toBe([])
        ->and($this->domains->planRelay($current, $relay(['relay_port' => ''])))->toBe([])
        ->and(mailPlan($this->domains->planRelay($current, $relay(['relay_port' => '2525']))))
        ->toBe([['v-add-mail-domain-smtp-relay', ['bob', 'example.com', 'smtp.relay.example', 'me', 'secret:', '2525']]])
        ->and(mailPlan($this->domains->planRelay($current, $relay(['relay_password' => 'n3w']))))
        ->toBe([['v-add-mail-domain-smtp-relay', ['bob', 'example.com', 'smtp.relay.example', 'me', 'secret:n3w', '587']]])
        ->and(array_column(mailPlan($this->domains->planRelay($current, $relay(['relay_host' => 'other.example']))), 0))->toBe(['v-add-mail-domain-smtp-relay'])
        ->and(array_column(mailPlan($this->domains->planRelay($current, $relay(['relay_user' => 'you']))), 0))->toBe(['v-add-mail-domain-smtp-relay']);
});

it('deletes the relay when switched off, and does nothing without one', function () {
    $off = ['relay' => false, 'relay_host' => '', 'relay_port' => '', 'relay_user' => '', 'relay_password' => ''];

    expect(mailPlan($this->domains->planRelay(mailDomain(['U_SMTP_RELAY' => 'true']), $off)))->toBe([['v-delete-mail-domain-smtp-relay', ['bob', 'example.com']]])
        ->and($this->domains->planRelay(mailDomain(), $off))->toBe([]);
});

// Accounts

it('adds an account with aliases, forwards, fwd-only and a rate limit', function () {
    $steps = $this->accounts->planCreate('bob', 'example.com', [
        'account' => 'info', 'password' => 'Secret-123', 'quota' => '', 'aliases' => ['sales', 'hello'],
        'forwards' => ['a@example.net'], 'blackhole' => false, 'fwd_only' => true, 'rate' => '30',
    ]);

    expect(mailPlan($steps))->toBe([
        ['v-add-mail-account', ['bob', 'example.com', 'info', 'secret:Secret-123', '0']],
        ['v-add-mail-account-alias', ['bob', 'example.com', 'info', 'sales']],
        ['v-add-mail-account-alias', ['bob', 'example.com', 'info', 'hello']],
        ['v-add-mail-account-forward', ['bob', 'example.com', 'info', 'a@example.net']],
        ['v-add-mail-account-fwd-only', ['bob', 'example.com', 'info']],
        ['v-change-mail-account-rate-limit', ['bob', 'example.com', 'info', '30']],
    ]);
});

it('adds :blackhole: once instead of the forwards, and an unlimited quota', function () {
    $steps = $this->accounts->planCreate('bob', 'example.com', [
        'account' => 'info', 'password' => 'Secret-123', 'quota' => 'unlimited', 'aliases' => [],
        'forwards' => [], 'blackhole' => true, 'fwd_only' => false, 'rate' => '',
    ]);

    expect(mailPlan($steps))->toBe([
        ['v-add-mail-account', ['bob', 'example.com', 'info', 'secret:Secret-123', 'unlimited']],
        ['v-add-mail-account-forward', ['bob', 'example.com', 'info', ':blackhole:']],
    ]);
});

it('changes password, quota and rate limit of an account only when given or changed', function () {
    $none = $this->accounts->planGeneral(mailAccount(), ['password' => '', 'quota' => '100', 'rate' => null]);
    $all = $this->accounts->planGeneral(mailAccount(['RATE_LIMIT' => '20']), ['password' => 'N3w-pass', 'quota' => 'unlimited', 'rate' => '']);
    $unlimited = $this->accounts->planGeneral(mailAccount(['QUOTA' => 'unlimited']), ['password' => '', 'quota' => '', 'rate' => null]);

    expect($none)->toBe([])->and($unlimited)->toBe([])
        ->and(mailPlan($all))->toBe([
            ['v-change-mail-account-password', ['bob', 'example.com', 'info', 'secret:N3w-pass']],
            ['v-change-mail-account-quota', ['bob', 'example.com', 'info', 'unlimited']],
            ['v-change-mail-account-rate-limit', ['bob', 'example.com', 'info', 'system']],
        ]);
});

it('diffs aliases and forwards', function () {
    $steps = $this->accounts->planForwarding(mailAccount(), [
        'aliases' => ['sales', 'support'], 'forwards' => ['b@example.net', 'c@example.net'], 'blackhole' => false, 'fwd_only' => false,
    ]);

    expect(mailPlan($steps))->toBe([
        ['v-delete-mail-account-alias', ['bob', 'example.com', 'info', 'hello']],
        ['v-add-mail-account-alias', ['bob', 'example.com', 'info', 'support']],
        ['v-delete-mail-account-forward', ['bob', 'example.com', 'info', 'a@example.net']],
        ['v-add-mail-account-forward', ['bob', 'example.com', 'info', 'c@example.net']],
    ]);
});

it('discards all mail: removes every forward and adds :blackhole: once (spec §11)', function () {
    $input = ['aliases' => ['sales', 'hello'], 'forwards' => [], 'blackhole' => true, 'fwd_only' => false];

    expect(mailPlan($this->accounts->planForwarding(mailAccount(), $input)))->toBe([
        ['v-delete-mail-account-forward', ['bob', 'example.com', 'info', 'a@example.net']],
        ['v-delete-mail-account-forward', ['bob', 'example.com', 'info', 'b@example.net']],
        ['v-add-mail-account-forward', ['bob', 'example.com', 'info', ':blackhole:']],
    ])->and($this->accounts->planForwarding(mailAccount(['FWD' => ':blackhole:']), $input))->toBe([]);
});

it('removes :blackhole: when discarding is switched off', function () {
    $steps = $this->accounts->planForwarding(mailAccount(['ALIAS' => '', 'FWD' => ':blackhole:']), [
        'aliases' => [], 'forwards' => ['a@example.net'], 'blackhole' => false, 'fwd_only' => false,
    ]);

    expect(mailPlan($steps))->toBe([
        ['v-delete-mail-account-forward', ['bob', 'example.com', 'info', ':blackhole:']],
        ['v-add-mail-account-forward', ['bob', 'example.com', 'info', 'a@example.net']],
    ]);
});

it('switches "Do not store forwarded mail" on and off', function () {
    $input = fn (bool $on) => ['aliases' => ['sales', 'hello'], 'forwards' => ['a@example.net', 'b@example.net'], 'blackhole' => false, 'fwd_only' => $on];

    expect(mailPlan($this->accounts->planForwarding(mailAccount(), $input(true))))->toBe([['v-add-mail-account-fwd-only', ['bob', 'example.com', 'info']]])
        ->and(mailPlan($this->accounts->planForwarding(mailAccount(['FWD_ONLY' => 'yes']), $input(false))))->toBe([['v-delete-mail-account-fwd-only', ['bob', 'example.com', 'info']]])
        ->and($this->accounts->planForwarding(mailAccount(), $input(false)))->toBe([]);
});

it('adds, changes and deletes the autoreply', function () {
    $on = mailAccount(['AUTOREPLY' => 'yes']);

    expect(mailPlan($this->accounts->planAutoreply(mailAccount(), '', ['autoreply' => true, 'message' => "Away\nback soon"])))
        ->toBe([['v-add-mail-account-autoreply', ['bob', 'example.com', 'info', 'Away\nback soon']]])
        ->and($this->accounts->planAutoreply($on, "Away\nback soon", ['autoreply' => true, 'message' => "Away\nback soon"]))->toBe([])
        ->and(array_column(mailPlan($this->accounts->planAutoreply($on, 'Away', ['autoreply' => true, 'message' => 'Gone'])), 0))->toBe(['v-add-mail-account-autoreply'])
        ->and(mailPlan($this->accounts->planAutoreply($on, 'Away', ['autoreply' => false, 'message' => 'Away'])))->toBe([['v-delete-mail-account-autoreply', ['bob', 'example.com', 'info']]])
        ->and($this->accounts->planAutoreply(mailAccount(), '', ['autoreply' => false, 'message' => 'x']))->toBe([]);
});
