<?php

namespace App\Services\MailDomains;

use App\Arc\Secret;
use App\Services\WebDomains\Step;

/**
 * The commands of adding and editing a mail account, in the order of
 * web/add/mail and web/edit/mail, only for the values that changed.
 */
final class MailAccountPlanner
{
    /**
     * web/add/mail?domain=: add the account, then its aliases, ":blackhole:"
     * or the forwards, "Do not store forwarded mail" and the rate limit
     * (administrators, when given).
     *
     * @param  array{account: string, password: string, quota: string, aliases: list<string>, forwards: list<string>, blackhole: bool, fwd_only: bool, rate: string|null}  $input  quota: MB, "unlimited" or '' (0); rate null: not editable
     * @return list<Step>
     */
    public function planCreate(string $user, string $domain, array $input): array
    {
        $account = $input['account'];
        $args = [$user, $domain, $account];

        $steps = [new Step(__('Add the mail account'), 'v-add-mail-account',
            [...$args, Secret::file($input['password']), $input['quota'] !== '' ? $input['quota'] : '0'])];

        foreach ($input['aliases'] as $alias) {
            $steps[] = new Step(sprintf(__('Add the alias %s'), $alias), 'v-add-mail-account-alias', [...$args, $alias]);
        }
        if ($input['blackhole']) {
            $steps[] = new Step(__('Discard all mail'), 'v-add-mail-account-forward', [...$args, MailAccountRecord::BLACKHOLE]);
        } else {
            foreach ($input['forwards'] as $forward) {
                $steps[] = new Step(sprintf(__('Add the forward to %s'), $forward), 'v-add-mail-account-forward', [...$args, $forward]);
            }
        }
        if ($input['fwd_only']) {
            $steps[] = new Step(__('Do not store forwarded mail'), 'v-add-mail-account-fwd-only', $args);
        }
        if ($input['rate'] !== null && $input['rate'] !== '') {
            $steps[] = new Step(__('Change the rate limit'), 'v-change-mail-account-rate-limit', [...$args, $input['rate']]);
        }

        return $steps;
    }

    /**
     * General tab of web/edit/mail?account=: password (when given), quota
     * and rate limit (administrators) when they changed.
     *
     * @param  array{password: string, quota: string, rate: string|null}  $input
     * @return list<Step>
     */
    public function planGeneral(MailAccountRecord $current, array $input): array
    {
        $args = [$current->user, $current->domain, $current->account];
        $steps = [];

        if ($input['password'] !== '') {
            $steps[] = new Step(__('Change the password'), 'v-change-mail-account-password', [...$args, Secret::file($input['password'])]);
        }

        $quota = $input['quota'] !== '' ? $input['quota'] : '0';
        if (self::quotaKey($quota) !== self::quotaKey($current->quota)) {
            $steps[] = new Step(__('Change the quota'), 'v-change-mail-account-quota', [...$args, $quota]);
        }

        if ($input['rate'] !== null && $input['rate'] !== $current->rateLimit) {
            $steps[] = new Step(__('Change the rate limit'), 'v-change-mail-account-rate-limit',
                [...$args, $input['rate'] !== '' ? $input['rate'] : 'system']);
        }

        return $steps;
    }

    /**
     * Aliases and forwarding tab: removed aliases, new aliases; with "Discard
     * all mail" every other forward goes and ":blackhole:" is added once
     * (spec §11), else the forwards are diffed (an old ":blackhole:" goes);
     * then "Do not store forwarded mail" on or off.
     *
     * @param  array{aliases: list<string>, forwards: list<string>, blackhole: bool, fwd_only: bool}  $input
     * @return list<Step>
     */
    public function planForwarding(MailAccountRecord $current, array $input): array
    {
        $args = [$current->user, $current->domain, $current->account];
        $steps = [];

        foreach (array_diff($current->aliases, $input['aliases']) as $alias) {
            $steps[] = new Step(sprintf(__('Delete the alias %s'), $alias), 'v-delete-mail-account-alias', [...$args, $alias]);
        }
        foreach (array_diff($input['aliases'], $current->aliases) as $alias) {
            $steps[] = new Step(sprintf(__('Add the alias %s'), $alias), 'v-add-mail-account-alias', [...$args, $alias]);
        }

        if ($input['blackhole']) {
            foreach ($current->forwardAddresses() as $forward) {
                $steps[] = new Step(sprintf(__('Delete the forward to %s'), $forward), 'v-delete-mail-account-forward', [...$args, $forward]);
            }
            if (! $current->discards()) {
                $steps[] = new Step(__('Discard all mail'), 'v-add-mail-account-forward', [...$args, MailAccountRecord::BLACKHOLE]);
            }
        } else {
            foreach (array_diff($current->forwards, $input['forwards']) as $forward) {
                $label = $forward === MailAccountRecord::BLACKHOLE ? __('Keep incoming mail') : sprintf(__('Delete the forward to %s'), $forward);
                $steps[] = new Step($label, 'v-delete-mail-account-forward', [...$args, $forward]);
            }
            foreach (array_diff($input['forwards'], $current->forwards) as $forward) {
                $steps[] = new Step(sprintf(__('Add the forward to %s'), $forward), 'v-add-mail-account-forward', [...$args, $forward]);
            }
        }

        if ($current->fwdOnly && ! $input['fwd_only']) {
            $steps[] = new Step(__('Store forwarded mail'), 'v-delete-mail-account-fwd-only', $args);
        } elseif (! $current->fwdOnly && $input['fwd_only']) {
            $steps[] = new Step(__('Do not store forwarded mail'), 'v-add-mail-account-fwd-only', $args);
        }

        return $steps;
    }

    /**
     * Autoreply tab: delete it when switched off, add (or overwrite) it when
     * on and the message differs from the stored one ($currentMessage, with
     * real line breaks).
     *
     * @param  array{autoreply: bool, message: string}  $input
     * @return list<Step>
     */
    public function planAutoreply(MailAccountRecord $current, string $currentMessage, array $input): array
    {
        $args = [$current->user, $current->domain, $current->account];
        if ($current->autoreply && ! $input['autoreply']) {
            return [new Step(__('Disable the autoreply'), 'v-delete-mail-account-autoreply', $args)];
        }
        $message = str_replace("\r\n", "\n", $input['message']);
        if ($input['autoreply'] && $message !== ($current->autoreply ? $currentMessage : '')) {
            // The command writes the message with `echo -e`: line breaks travel as "\n".
            return [new Step(__('Change the autoreply'), 'v-add-mail-account-autoreply', [...$args, str_replace("\n", '\n', $message)])];
        }

        return [];
    }

    /** "unlimited", 0 and empty mean the same quota. */
    private static function quotaKey(string $quota): string
    {
        return in_array($quota, ['', '0', 'unlimited'], true) ? 'unlimited' : $quota;
    }
}
