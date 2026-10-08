<?php

namespace App\Http\Controllers\Api\V2;

use App\Api\ApiException;
use App\Api\ErrorCode;
use App\Api\Paginates;
use App\Api\PlanGate;
use App\Auth\Principal;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V2\MoveFirewallRuleRequest;
use App\Http\Requests\Api\V2\StoreFirewallRuleRequest;
use App\Http\Requests\Api\V2\UpdateFirewallRuleRequest;
use App\Http\Resources\V2\FirewallRuleResource;
use App\Policies\FirewallPolicy;
use App\Services\Firewall\FirewallActions;
use App\Services\Firewall\FirewallRule;
use App\Services\Firewall\FirewallService;
use App\Services\WebDomains\Step;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

/**
 * Firewall rules of the server for administrators (E34 batch 6 spec §5.3,
 * D59): Admin → Server → Firewall as JSON, with the rules of
 * FirewallActions. A rule's id is its position in the rule order. The port
 * registry's rules (`managed`) can be read and moved, not changed,
 * suspended or deleted. FIREWALL_SYSTEM off answers 404.
 *
 * @tags Server
 */
final class FirewallRuleController extends Controller
{
    public function __construct(
        private readonly FirewallService $firewall,
        private readonly FirewallActions $actions,
        private readonly PlanGate $gate,
        private readonly Paginates $pages,
    ) {}

    /**
     * List the firewall rules
     *
     * In the order they apply. A rule's id is its position: moving a rule or
     * deleting one before it changes it.
     *
     * @operationId listFirewallRules
     */
    public function index(Request $request, Principal $principal, FirewallPolicy $policy): AnonymousResourceCollection
    {
        abort_unless($policy->viewAny($principal), 403);
        $page = $this->pages->slice($request, array_values($this->firewall->rules()));

        return FirewallRuleResource::collection($page['items'])->additional(['meta' => $page['meta']]);
    }

    /**
     * Add a firewall rule
     *
     * The rule goes after the last one; its id is that position. A rule
     * with the same action, protocol, port and source answers `409
     * E_EXISTS` with `existing`. Comments of the form `arcpanel-port-…`
     * belong to the port registry and are refused.
     *
     * @operationId createFirewallRule
     *
     * @status 201
     */
    public function store(StoreFirewallRuleRequest $request, Principal $principal, FirewallPolicy $policy): FirewallRuleResource|JsonResponse
    {
        abort_unless($policy->manage($principal), 403);
        $data = $request->validated();
        $values = [
            'action' => (string) $data['action'],
            'protocol' => (string) $data['protocol'],
            'port' => FirewallService::normalizePort((string) ($data['port'] ?? '0')),
            'source' => (string) $data['source'],
            'comment' => (string) ($data['comment'] ?? ''),
        ];
        self::assertPort($values['port']);
        self::assertComment($values['comment']);
        $duplicate = $this->actions->duplicateOf($values['action'], $values['protocol'], $values['port'], $values['source']);
        if ($duplicate !== null) {
            throw new ApiException(ErrorCode::Exists, sprintf(__('Rule %s is the same rule.'), $duplicate->id), 409, existing: self::path($duplicate->id));
        }
        $id = $this->actions->nextId();

        $step = FirewallService::planCreate($values['action'], $values['source'], $values['port'], $values['protocol'], $values['comment'], $id);
        if ($preview = $this->gate->execute([$step], $this->firewall->apply(...))) {
            return $preview;
        }

        return (new FirewallRuleResource($this->fresh((string) $id)))->response()->setStatusCode(201)
            ->header('Location', route('api.v2.system.firewall.rules.show', ['rule' => $id]));
    }

    /**
     * Get a firewall rule
     *
     * The id is the rule's position in the rule order.
     *
     * @operationId getFirewallRule
     */
    public function show(string $rule, Principal $principal, FirewallPolicy $policy): FirewallRuleResource
    {
        abort_unless($policy->viewAny($principal), 403);

        return new FirewallRuleResource($this->rule($rule));
    }

    /**
     * Update a firewall rule
     *
     * Fields left out keep their value; the id (the rule's position) does
     * not change. The port registry's rules answer `403`.
     *
     * @operationId updateFirewallRule
     */
    public function update(UpdateFirewallRuleRequest $request, string $rule, Principal $principal, FirewallPolicy $policy): FirewallRuleResource|JsonResponse
    {
        abort_unless($policy->manage($principal), 403);
        $record = $this->changeable($rule);
        $data = $request->validated();
        $values = [
            'action' => (string) ($data['action'] ?? $record->action),
            'protocol' => (string) ($data['protocol'] ?? $record->protocol),
            'port' => FirewallService::normalizePort((string) ($data['port'] ?? $record->port)),
            'source' => (string) ($data['source'] ?? $record->ip),
            'comment' => array_key_exists('comment', $data) ? (string) $data['comment'] : $record->comment,
        ];
        self::assertPort($values['port']);
        self::assertComment($values['comment']);
        $unchanged = $values === [
            'action' => $record->action, 'protocol' => $record->protocol, 'port' => FirewallService::normalizePort($record->port),
            'source' => $record->ip, 'comment' => $record->comment,
        ];
        $steps = $unchanged ? [] : [FirewallService::planUpdate($record->id, $values['action'], $values['source'], $values['port'], $values['protocol'], $values['comment'])];

        return $this->gate->execute($steps, $this->firewall->apply(...)) ?? new FirewallRuleResource($this->fresh($record->id));
    }

    /**
     * Delete a firewall rule
     *
     * The rules after it move up one position, so their ids drop by one.
     * The port registry's rules answer `403`.
     *
     * @operationId deleteFirewallRule
     *
     * @status 204
     */
    public function destroy(string $rule, Principal $principal, FirewallPolicy $policy): Response|JsonResponse
    {
        abort_unless($policy->manage($principal), 403);
        $record = $this->changeable($rule);

        return $this->gate->execute([FirewallService::planRule('delete', $record->id)], $this->firewall->apply(...)) ?? response()->noContent();
    }

    /**
     * Move a firewall rule
     *
     * Swaps the rule with the one before it (`up`) or after it (`down`): the
     * two exchange their ids, which are their positions. Answers the rule
     * with its new id; `Content-Location` names its new path (not `Location`,
     * which PHP would turn into a `302` redirect). The port registry's rules
     * move too.
     *
     * @operationId moveFirewallRule
     */
    public function move(MoveFirewallRuleRequest $request, string $rule, Principal $principal, FirewallPolicy $policy): FirewallRuleResource|JsonResponse
    {
        abort_unless($policy->manage($principal), 403);
        $record = $this->rule($rule);
        $direction = (string) $request->validated()['direction'];
        $neighbour = $this->actions->neighbour($record, $direction);
        if ($neighbour === null) {
            throw ValidationException::withMessages(['direction' => $this->noNeighbour($record, $direction)]);
        }

        if ($preview = $this->gate->execute([FirewallService::planMove($record->id, $direction)], $this->firewall->apply(...))) {
            return $preview;
        }

        return (new FirewallRuleResource($this->fresh($neighbour->id)))->response()
            ->header('Content-Location', route('api.v2.system.firewall.rules.show', ['rule' => (int) $neighbour->id]));
    }

    /**
     * Suspend a firewall rule
     *
     * The id is the rule's position. Nothing runs when the rule is
     * suspended already. The port registry's rules answer `403`.
     *
     * @operationId suspendFirewallRule
     */
    public function suspend(string $rule, Principal $principal, FirewallPolicy $policy): FirewallRuleResource|JsonResponse
    {
        return $this->setSuspended($rule, true, $principal, $policy);
    }

    /**
     * Unsuspend a firewall rule
     *
     * The id is the rule's position. Nothing runs when the rule is active
     * already. The port registry's rules answer `403`.
     *
     * @operationId unsuspendFirewallRule
     */
    public function unsuspend(string $rule, Principal $principal, FirewallPolicy $policy): FirewallRuleResource|JsonResponse
    {
        return $this->setSuspended($rule, false, $principal, $policy);
    }

    private function setSuspended(string $rule, bool $suspend, Principal $principal, FirewallPolicy $policy): FirewallRuleResource|JsonResponse
    {
        abort_unless($policy->manage($principal), 403);
        $record = $this->changeable($rule);
        /** @var list<Step> $steps */
        $steps = $record->suspended === $suspend ? [] : [FirewallService::planRule($suspend ? 'suspend' : 'unsuspend', $record->id)];

        return $this->gate->execute($steps, $this->firewall->apply(...)) ?? new FirewallRuleResource($this->fresh($record->id));
    }

    private function rule(string $id): FirewallRule
    {
        return $this->actions->existingRules([$id])[0];
    }

    /** The rule, unless the port registry manages it (403). */
    private function changeable(string $id): FirewallRule
    {
        $record = $this->rule($id);
        if (! FirewallActions::changeable($record)) {
            throw new ApiException(ErrorCode::Forbidden, sprintf(__('Rule %s belongs to the port registry: change it through the ports of its user.'), $record->id), 403);
        }

        return $record;
    }

    /** The rule as the commands left it (FirewallActions keeps its own read of the rules). */
    private function fresh(string $id): FirewallRule
    {
        $this->firewall->forget();
        $rule = $this->firewall->rules()[$id] ?? null;
        abort_if($rule === null, 404);

        return $rule;
    }

    private function noNeighbour(FirewallRule $rule, string $direction): string
    {
        $ids = array_map('intval', array_keys($this->firewall->rules()));
        if ($direction === 'up' && (int) $rule->id <= min($ids)) {
            return sprintf(__('Rule %s is the first rule.'), $rule->id);
        }
        if ($direction === 'down' && (int) $rule->id >= max($ids)) {
            return sprintf(__('Rule %s is the last rule.'), $rule->id);
        }

        return sprintf(__('There is no rule at position %d to swap with.'), (int) $rule->id + ($direction === 'up' ? -1 : 1));
    }

    private static function assertPort(string $port): void
    {
        if ($port === '') {
            throw ValidationException::withMessages(['port' => __('The port is empty.')]);
        }
    }

    private static function assertComment(string $comment): void
    {
        if (FirewallPolicy::isManaged($comment)) {
            throw ValidationException::withMessages(['comment' => __('Comments of the form arcpanel-port-PORT-USER belong to the port registry.')]);
        }
    }

    private static function path(string $id): string
    {
        return '/api/v2/system/firewall/rules/'.$id;
    }
}
