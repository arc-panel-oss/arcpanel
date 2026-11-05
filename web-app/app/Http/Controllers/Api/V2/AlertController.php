<?php

namespace App\Http\Controllers\Api\V2;

use App\Api\ApiException;
use App\Api\ErrorCode;
use App\Api\Paginates;
use App\Api\PlanGate;
use App\Auth\OwnerScope;
use App\Auth\Principal;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V2\CreateAlertChannelRequest;
use App\Http\Requests\Api\V2\ListAlertHistoryRequest;
use App\Http\Requests\Api\V2\UpdateAlertChannelRequest;
use App\Http\Requests\Api\V2\UpdateAlertRuleRequest;
use App\Http\Resources\V2\AlertChannelResource;
use App\Http\Resources\V2\AlertHistoryResource;
use App\Http\Resources\V2\AlertResource;
use App\Http\Resources\V2\AlertRuleResource;
use App\Policies\AlertPolicy;
use App\Services\Alerts\AlertIndex;
use App\Services\Alerts\AlertIndexUnavailable;
use App\Services\Alerts\AlertStore;
use App\Services\Support\PlanRunner;
use App\Services\WebDomains\Step;
use App\Tenancy\TenancyDirectory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * Alerts and notification channels (E16 spec §8): the open alerts, the rules,
 * the channels and the history of a scope. `/system/…` is the server scope
 * (administrators), `/users/{user}/…` the scope of an account, which a
 * reseller reaches for its customers. Channel secrets (the Telegram bot
 * token, the incoming-webhook URL) are write-only.
 *
 * @tags Alerts
 */
final class AlertController extends Controller
{
    public function __construct(
        private readonly AlertStore $alerts,
        private readonly PlanGate $gate,
        private readonly PlanRunner $runner,
        private readonly TenancyDirectory $directory,
        private readonly Paginates $pages,
    ) {}

    /**
     * List the open alerts the key sees
     *
     * The open metric alerts of the server scope (administrators) and of
     * every account scope in the key's reach, sorted by id.
     *
     * @operationId listAlerts
     */
    public function all(Request $request, Principal $principal, AlertPolicy $policy, OwnerScope $owners): AnonymousResourceCollection
    {
        abort_unless($policy->view($principal, 'system') || $policy->view($principal, $principal->effectiveUser()), 403);
        $rows = array_values(array_filter($this->alerts->open('all'), function (array $row) use ($principal, $policy, $owners) {
            $scope = $row['SCOPE'] ?? '';

            return $scope === 'system' ? $policy->view($principal, 'system') : ($owners->canSee($scope) && $policy->view($principal, $scope));
        }));
        $page = $this->pages->slice($request, $rows);

        return AlertResource::collection($page['items'])->additional(['meta' => $page['meta']]);
    }

    /**
     * List the open alerts of an account
     *
     * @operationId listUserAlerts
     */
    public function open(Request $request, string $user, Principal $principal, AlertPolicy $policy): AnonymousResourceCollection
    {
        $this->assertScope($user, $principal, $policy);
        $page = $this->pages->slice($request, array_values($this->alerts->open($user)));

        return AlertResource::collection($page['items'])->additional(['meta' => $page['meta']]);
    }

    /**
     * List the alert rules of an account
     *
     * Every rule type of the catalog that applies to the account, with the
     * catalog default for a rule the account has not changed.
     *
     * @operationId listAlertRules
     */
    public function rules(Request $request, string $user, Principal $principal, AlertPolicy $policy): AnonymousResourceCollection
    {
        $this->assertScope($user, $principal, $policy);

        $page = $this->pages->slice($request, array_values($this->alerts->rules($user)));

        return AlertRuleResource::collection($page['items'])->additional(['meta' => $page['meta']]);
    }

    /**
     * List the alert rules of the server
     *
     * Every server rule type of the catalog, with the catalog default for a
     * rule the server has not changed.
     *
     * @operationId listSystemAlertRules
     */
    public function systemRules(Request $request, Principal $principal, AlertPolicy $policy): AnonymousResourceCollection
    {
        $this->assertScope('system', $principal, $policy);

        $page = $this->pages->slice($request, array_values($this->alerts->rules('system')));

        return AlertRuleResource::collection($page['items'])->additional(['meta' => $page['meta']]);
    }

    /**
     * Show an alert rule of an account
     *
     * @operationId getAlertRule
     */
    public function rule(string $user, string $rule, Principal $principal, AlertPolicy $policy): AlertRuleResource
    {
        $this->assertScope($user, $principal, $policy);

        return new AlertRuleResource($this->alerts->rules($user)[$rule] ?? abort(404));
    }

    /**
     * Show an alert rule of the server
     *
     * @operationId getSystemAlertRule
     */
    public function systemRule(string $rule, Principal $principal, AlertPolicy $policy): AlertRuleResource
    {
        $this->assertScope('system', $principal, $policy);

        return new AlertRuleResource($this->alerts->rules('system')[$rule] ?? abort(404));
    }

    /**
     * Change an alert rule of an account
     *
     * Fields left out keep their value. `warning` and `critical` are the
     * thresholds of a metric rule in its `unit`, `for` the minutes the
     * condition must hold, `severity` the lowest severity an event rule
     * sends, `cooldown` the minutes between two messages, `channels` the
     * channel ids of the scope and `options` the services or mount points
     * to watch. A value the command refuses answers 422 `E_INVALID`.
     *
     * @operationId updateAlertRule
     */
    public function updateRule(UpdateAlertRuleRequest $request, string $user, string $rule, Principal $principal, AlertPolicy $policy): AlertRuleResource|JsonResponse
    {
        return $this->ruleUpdate($request->validated(), $user, $rule, $principal, $policy);
    }

    /**
     * Change an alert rule of the server
     *
     * The fields of `updateAlertRule`; fields left out keep their value.
     *
     * @operationId updateSystemAlertRule
     */
    public function updateSystemRule(UpdateAlertRuleRequest $request, string $rule, Principal $principal, AlertPolicy $policy): AlertRuleResource|JsonResponse
    {
        return $this->ruleUpdate($request->validated(), 'system', $rule, $principal, $policy);
    }

    /**
     * List the alert channels of an account
     *
     * Secrets are never listed: `secret_set` says whether one is stored.
     *
     * @operationId listAlertChannels
     */
    public function channels(Request $request, string $user, Principal $principal, AlertPolicy $policy): AnonymousResourceCollection
    {
        $this->assertScope($user, $principal, $policy);

        $page = $this->pages->slice($request, array_values($this->alerts->channels($user)));

        return AlertChannelResource::collection($page['items'])->additional(['meta' => $page['meta']]);
    }

    /**
     * List the alert channels of the server
     *
     * Secrets are never listed: `secret_set` says whether one is stored.
     *
     * @operationId listSystemAlertChannels
     */
    public function systemChannels(Request $request, Principal $principal, AlertPolicy $policy): AnonymousResourceCollection
    {
        $this->assertScope('system', $principal, $policy);

        $page = $this->pages->slice($request, array_values($this->alerts->channels('system')));

        return AlertChannelResource::collection($page['items'])->additional(['meta' => $page['meta']]);
    }

    /**
     * Add an alert channel to an account
     *
     * `type` is `email` (`target`: one to five addresses, comma-separated),
     * `telegram` (`target`: the chat id; `secret`: the bot token), `slack`,
     * `discord` or `mattermost` (`secret`: the incoming-webhook URL). The
     * secret goes to the secret store of the account and is never returned.
     *
     * @operationId createAlertChannel
     *
     * @status 201
     */
    public function storeChannel(CreateAlertChannelRequest $request, string $user, Principal $principal, AlertPolicy $policy): AlertChannelResource|JsonResponse
    {
        return $this->channelStore($request->validated(), $user, $principal, $policy);
    }

    /**
     * Add an alert channel to the server
     *
     * The fields of `createAlertChannel`; the secret goes to the secret store
     * of the administrator account.
     *
     * @operationId createSystemAlertChannel
     *
     * @status 201
     */
    public function storeSystemChannel(CreateAlertChannelRequest $request, Principal $principal, AlertPolicy $policy): AlertChannelResource|JsonResponse
    {
        return $this->channelStore($request->validated(), 'system', $principal, $policy);
    }

    /**
     * Change an alert channel of an account
     *
     * `name` and `enabled` are required; `target` replaces the addresses or
     * chat id; `secret` replaces the stored token or URL, which stays when it
     * is left out. Turning a channel on clears its failures.
     *
     * @operationId updateAlertChannel
     */
    public function updateChannel(UpdateAlertChannelRequest $request, string $user, string $id, Principal $principal, AlertPolicy $policy): AlertChannelResource|JsonResponse
    {
        return $this->channelUpdate($request->validated(), $user, $id, $principal, $policy);
    }

    /**
     * Change an alert channel of the server
     *
     * The fields of `updateAlertChannel`.
     *
     * @operationId updateSystemAlertChannel
     */
    public function updateSystemChannel(UpdateAlertChannelRequest $request, string $id, Principal $principal, AlertPolicy $policy): AlertChannelResource|JsonResponse
    {
        return $this->channelUpdate($request->validated(), 'system', $id, $principal, $policy);
    }

    /**
     * Delete an alert channel of an account
     *
     * Deletes its secret and removes it from the rules of the account. The
     * panel channel cannot be deleted (403 `E_FORBIDDEN`).
     *
     * @operationId deleteAlertChannel
     *
     * @status 204
     */
    public function destroyChannel(string $user, string $id, Principal $principal, AlertPolicy $policy): Response|JsonResponse
    {
        return $this->channelDestroy($user, $id, $principal, $policy);
    }

    /**
     * Delete an alert channel of the server
     *
     * Deletes its secret and removes it from the server rules. The panel
     * channel cannot be deleted (403 `E_FORBIDDEN`).
     *
     * @operationId deleteSystemAlertChannel
     *
     * @status 204
     */
    public function destroySystemChannel(string $id, Principal $principal, AlertPolicy $policy): Response|JsonResponse
    {
        return $this->channelDestroy('system', $id, $principal, $policy);
    }

    /**
     * Send a test message to an alert channel of an account
     *
     * Sends now, also to a channel that is off, and answers the result:
     * `ok` false with the delivery's `error`.
     *
     * @operationId testAlertChannel
     */
    public function testChannel(string $user, string $id, Principal $principal, AlertPolicy $policy): JsonResponse
    {
        return $this->channelTest($user, $id, $principal, $policy);
    }

    /**
     * Send a test message to an alert channel of the server
     *
     * Sends now, also to a channel that is off, and answers the result:
     * `ok` false with the delivery's `error`.
     *
     * @operationId testSystemAlertChannel
     */
    public function testSystemChannel(string $id, Principal $principal, AlertPolicy $policy): JsonResponse
    {
        return $this->channelTest('system', $id, $principal, $policy);
    }

    /**
     * List the alert history of an account
     *
     * Newest first: fired, raised, reminded and resolved alerts and events
     * with their deliveries. Filters: `rule`, `severity`, `since`.
     *
     * @operationId listAlertHistory
     */
    public function history(ListAlertHistoryRequest $request, string $user, Principal $principal, AlertPolicy $policy, AlertIndex $index): AnonymousResourceCollection
    {
        $this->assertScope($user, $principal, $policy);
        $page = $this->historyPage($request, $user, $index);

        return AlertHistoryResource::collection($page['rows'])->additional(['meta' => $page['meta']]);
    }

    /**
     * List the alert history of the server
     *
     * Newest first: fired, raised, reminded and resolved alerts and events
     * of the server scope with their deliveries. Filters: `rule`,
     * `severity`, `since`.
     *
     * @operationId listSystemAlertHistory
     */
    public function systemHistory(ListAlertHistoryRequest $request, Principal $principal, AlertPolicy $policy, AlertIndex $index): AnonymousResourceCollection
    {
        $this->assertScope('system', $principal, $policy);
        $page = $this->historyPage($request, 'system', $index);

        return AlertHistoryResource::collection($page['rows'])->additional(['meta' => $page['meta']]);
    }

    /** @param  array<string, mixed>  $data */
    private function ruleUpdate(array $data, string $user, string $rule, Principal $principal, AlertPolicy $policy): AlertRuleResource|JsonResponse
    {
        $this->assertScope($user, $principal, $policy, true);
        $current = $this->alerts->rules($user)[$rule] ?? abort(404);
        $value = fn (string $key, string $field) => array_key_exists($field, $data) ? (string) ($data[$field] ?? '') : (string) ($current[$key] ?? '');
        $args = AlertStore::ruleArgs($user, $rule, [
            'enabled' => array_key_exists('enabled', $data) ? (bool) $data['enabled'] : ($current['ENABLED'] ?? '') === 'yes',
            'warning' => $value('WARNING', 'warning'),
            'critical' => $value('CRITICAL', 'critical'),
            'for' => $value('FOR', 'for'),
            'severity' => $value('SEVERITY', 'severity'),
            'cooldown' => $value('COOLDOWN', 'cooldown'),
            'channels' => array_key_exists('channels', $data) ? array_values($data['channels']) : array_values(array_filter(explode(',', (string) ($current['CHANNELS'] ?? '')))),
            'options' => $value('OPTIONS', 'options'),
        ]);
        $steps = [new Step(sprintf(__('Change alert rule %s'), $rule), 'v-change-alert-rule', $args)];

        return $this->gate->execute($steps, $this->runner->apply(...)) ?? new AlertRuleResource($this->alerts->rules($user)[$rule]);
    }

    /** @param  array<string, mixed>  $data */
    private function channelStore(array $data, string $user, Principal $principal, AlertPolicy $policy): AlertChannelResource|JsonResponse
    {
        $this->assertScope($user, $principal, $policy, true);
        $secret = in_array($data['type'], AlertStore::SECRET_TYPES, true) ? (string) $data['secret'] : null;
        $args = AlertStore::addArgs($user, $data['name'], $data['type'], (string) ($data['target'] ?? ''), $secret);
        $steps = [new Step(sprintf(__('Add alert channel %s'), $data['name']), 'v-add-alert-channel', $args)];
        $this->gate->authorize($steps);
        if ($this->gate->dryRun()) {
            return $this->gate->preview($steps);
        }
        $before = array_keys($this->alerts->channels($user));
        $this->gate->assertApplied($this->runner->apply($steps));
        $channels = $this->alerts->channels($user);
        $new = array_values(array_diff(array_keys($channels), $before));
        $channel = $channels[$new[0] ?? ''] ?? abort(500);

        return (new AlertChannelResource($channel))->response()->setStatusCode(201);
    }

    /** @param  array<string, mixed>  $data */
    private function channelUpdate(array $data, string $user, string $id, Principal $principal, AlertPolicy $policy): AlertChannelResource|JsonResponse
    {
        $this->assertScope($user, $principal, $policy, true);
        $channel = $this->channel($user, $id);
        // A new secret goes after the target: the current one when none is given
        $target = array_key_exists('target', $data) ? (string) ($data['target'] ?? '')
            : (($data['secret'] ?? '') !== '' ? $channel['TARGET'] : null);
        $args = AlertStore::changeArgs($user, $id, $data['name'], (bool) $data['enabled'], $target, $data['secret'] ?? null);
        $steps = [new Step(sprintf(__('Change alert channel %s'), $channel['NAME']), 'v-change-alert-channel', $args)];

        return $this->gate->execute($steps, $this->runner->apply(...)) ?? new AlertChannelResource($this->channel($user, $id));
    }

    private function channelDestroy(string $user, string $id, Principal $principal, AlertPolicy $policy): Response|JsonResponse
    {
        $this->assertScope($user, $principal, $policy, true);
        $channel = $this->channel($user, $id);
        if ($channel['TYPE'] === 'panel') {
            throw new ApiException(ErrorCode::Forbidden, __('The panel channel cannot be deleted.'), 403);
        }
        $steps = [new Step(sprintf(__('Delete alert channel %s'), $channel['NAME']), 'v-delete-alert-channel', [$user, $id])];

        return $this->gate->execute($steps, $this->runner->apply(...)) ?? response()->noContent();
    }

    private function channelTest(string $user, string $id, Principal $principal, AlertPolicy $policy): JsonResponse
    {
        $this->assertScope($user, $principal, $policy, true);
        $this->channel($user, $id);
        $steps = [new Step(__('Send a test message'), 'v-test-alert-channel', [$user, $id, 'json'])];
        $this->gate->authorize($steps);
        if ($this->gate->dryRun()) {
            return $this->gate->preview($steps);
        }
        $result = $this->alerts->testChannel($user, $id);

        return response()->json(['data' => [
            /** @var bool */
            'ok' => $result['ok'],
            /** @var string|null */
            'error' => $result['ok'] ? null : $result['error'],
        ]]);
    }

    /**
     * One page of the history of a scope, newest first.
     *
     * @return array{rows: list<mixed>, meta: array{total: int, limit: int, offset: int}}
     */
    private function historyPage(ListAlertHistoryRequest $request, string $user, AlertIndex $index): array
    {
        $data = $request->validated();
        ['limit' => $limit, 'offset' => $offset] = $this->pages->window($request);
        try {
            $index->refresh();
            $query = $index->query()->orderByDesc('fired_at')->orderByDesc('id');
        } catch (AlertIndexUnavailable) {
            throw new ApiException(ErrorCode::Server, __('The alert history could not be read.'), 503);
        }
        if ($user === 'system') {
            $query->where('scope', 'system');
        } else {
            $query->where('scope', 'user')->where('user', $user);
        }
        foreach (['rule', 'severity'] as $field) {
            if (isset($data[$field])) {
                $query->where($field, $data[$field]);
            }
        }
        if (isset($data['since'])) {
            $query->where('fired_at', '>=', date('Y-m-d H:i:s', (int) strtotime((string) $data['since'])));
        }
        $total = (clone $query)->count();
        $limit ??= 100;
        $rows = $query->limit($limit)->offset($offset)->get()->all();

        return ['rows' => $rows, 'meta' => ['total' => $total, 'limit' => $limit, 'offset' => $offset]];
    }

    /** 404 for an account outside the key's reach or that does not exist; 403 without the capability. */
    private function assertScope(string $user, Principal $principal, AlertPolicy $policy, bool $change = false): void
    {
        if ($user !== 'system') {
            abort_unless(array_key_exists($user, $this->directory->all()), 404);
        }
        abort_unless($policy->view($principal, $user), $user === 'system' ? 403 : 404);
        if ($change) {
            abort_unless($policy->manage($principal, $user), 403);
        }
    }

    /** @return array<string, string> */
    private function channel(string $user, string $id): array
    {
        return $this->alerts->channels($user)[$id] ?? abort(404);
    }
}
