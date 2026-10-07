<?php

use App\Api\ApiException;
use App\Api\ErrorCode;
use App\Api\ErrorRenderer;
use App\Api\KeyPermissions;
use App\Api\KeyScopedArcCommand;
use App\Api\PlanGate;
use App\Arc\ArcCommand;
use App\Arc\ArcCommandException;
use App\Arc\CommandResult;
use App\Arc\ExitCode;
use App\Arc\Secret;
use App\Auth\Role;
use App\Http\Middleware\Api\ApiEnabled;
use App\Http\Middleware\Api\AuthenticateAccessKey;
use App\Services\WebDomains\ApplyResult;
use App\Services\WebDomains\Step;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

// REST API v2 building blocks (E34 spec §4, D5, D10, D11).

function keyWith(array $commands): KeyPermissions
{
    return new KeyPermissions(API_KEY_ID, 'bob', Role::User, ['v2-write'], $commands);
}

it('reads the key from v-check-access-key-permissions', function () {
    $key = KeyPermissions::fromCommand(['ACCESS_KEY_ID' => API_KEY_ID, 'USER' => 'hostco', 'ROLE' => 'reseller', 'PERMISSIONS' => ['v2-read'], 'COMMANDS' => ['v-list-user', '']]);

    expect($key->user)->toBe('hostco')
        ->and($key->role)->toBe(Role::Reseller)
        ->and($key->commands)->toBe(['v-list-user'])
        ->and($key->allows('v-list-user'))->toBeTrue()
        ->and($key->allows('v-add-user'))->toBeFalse()
        ->and($key->missing(['v-list-user', 'v-add-user', 'v-add-user']))->toBe(['v-add-user'])
        ->and(keyWith(['*'])->allows('v-anything'))->toBeTrue();
});

it('parses the bearer credentials', function () {
    expect(AuthenticateAccessKey::credentials('Bearer '.API_KEY_ID.':'.API_SECRET))->toBe([API_KEY_ID, API_SECRET])
        ->and(AuthenticateAccessKey::credentials('bearer '.API_KEY_ID.':'.API_SECRET))->toBeNull()
        ->and(AuthenticateAccessKey::credentials('Bearer '.API_KEY_ID.':short'))->toBeNull()
        ->and(AuthenticateAccessKey::credentials('Bearer '.API_KEY_ID.':'.API_SECRET.' x'))->toBeNull();
});

it('applies API_ALLOWED_IP like API v1', function () {
    expect(ApiEnabled::ipAllowed('allow-all', '198.51.100.1'))->toBeTrue()
        ->and(ApiEnabled::ipAllowed('198.51.100.1, 10.0.0.1', '10.0.0.1'))->toBeTrue()
        ->and(ApiEnabled::ipAllowed('0.0.0.0', '10.0.0.1'))->toBeTrue()
        ->and(ApiEnabled::ipAllowed('198.51.100.1', '10.0.0.1'))->toBeFalse()
        ->and(ApiEnabled::ipAllowed('', ''))->toBeFalse();
});

it('refuses a command outside the sets before it runs', function () {
    $fake = fakeArc();
    $fake->on('v-list-user')->returnsJson(['bob' => userRecord()]);
    $arc = new KeyScopedArcCommand(app(ArcCommand::class), keyWith(['v-list-user']));

    expect($arc->json('v-list-user', ['bob']))->toHaveKey('bob');
    expect(fn () => $arc->run('v-delete-user', ['bob']))->toThrow(ApiException::class, 'v-delete-user');
    $fake->assertNotRan('v-delete-user');
});

it('checks a whole plan before its first step and runs nothing when refused', function () {
    $gate = new PlanGate(keyWith(['v-add-user']), Request::create('/api/v2/users', 'POST'));
    $steps = [new Step('Add', 'v-add-user', ['x']), new Step('Role', 'v-change-user-role', ['x', 'reseller'])];
    $ran = false;

    try {
        $gate->execute($steps, function () use (&$ran) {
            $ran = true;

            return new ApplyResult([]);
        }, ['v-update-reseller-counters']);
        $this->fail('The plan was not refused.');
    } catch (ApiException $e) {
        expect($e->status)->toBe(403)
            ->and($e->body()['error']['code'])->toBe('E_FORBIDDEN')
            ->and($e->body()['error']['commands'])->toBe(['v-change-user-role', 'v-update-reseller-counters']);
    }
    expect($ran)->toBeFalse();
});

it('answers a dry run with the masked steps and runs nothing', function () {
    $gate = new PlanGate(keyWith(['*']), Request::create('/api/v2/users?dry_run=1', 'POST'));
    $steps = [new Step('Add user x', 'v-add-user', ['x', Secret::file('hunter2'), 'x@example.com'])];

    $response = $gate->execute($steps, fn () => throw new LogicException('ran'));

    expect($response->getStatusCode())->toBe(200)
        ->and($response->getData(true))->toBe(['data' => ['dry_run' => true, 'steps' => [
            ['label' => 'Add user x', 'command' => 'v-add-user', 'args' => ['x', '******', 'x@example.com']],
        ]]])
        ->and((string) $response->getContent())->not->toContain('hunter2');
});

it('turns a failed step into an error naming it and the steps before it', function () {
    $gate = new PlanGate(keyWith(['*']), Request::create('/api/v2/users', 'POST'));
    $add = new Step('Add user x', 'v-add-user', ['x']);
    $role = new Step('Set the role', 'v-change-user-role', ['x', 'reseller']);

    try {
        $gate->assertApplied(new ApplyResult([$add], $role, 'Error: no such role', ExitCode::Invalid->value));
        $this->fail('No error.');
    } catch (ApiException $e) {
        expect($e->status)->toBe(422)->and($e->body())->toBe(['error' => [
            'code' => 'E_INVALID',
            'message' => 'Error: no such role',
            'step' => ['label' => 'Set the role', 'command' => 'v-change-user-role'],
            'applied' => [['label' => 'Add user x', 'command' => 'v-add-user']],
        ]]);
    }
});

it('maps exceptions to the error codes and statuses of D10', function (Throwable $e, string $code, int $status) {
    $error = ErrorRenderer::toApiException($e);

    expect($error->errorCode->value)->toBe($code)->and($error->status)->toBe($status);
})->with([
    'exists' => [fn () => ArcCommandException::failed(new CommandResult('v-add-user', [], 4, 'Error: exists')), 'E_EXISTS', 409],
    'forbidden is spelled right' => [fn () => ArcCommandException::failed(new CommandResult('v-add-user', [], 10, 'Error: no')), 'E_FORBIDDEN', 403],
    'limit' => [fn () => ArcCommandException::failed(new CommandResult('v-add-user', [], 8, 'Error: limit')), 'E_LIMIT', 429],
    'not run' => [fn () => ArcCommandException::refused('v-x', 'timed out'), 'E_SERVER', 500],
    'validation' => [fn () => ValidationException::withMessages(['name' => 'bad']), 'E_VALIDATION', 422],
    '404' => [fn () => new NotFoundHttpException, 'E_NOTEXIST', 404],
    'other' => [fn () => new RuntimeException('boom'), 'E_SERVER', 500],
]);

it('keeps the error codes of the command exit codes', function () {
    foreach (ExitCode::cases() as $exit) {
        expect(ErrorCode::fromExit($exit))->toBeInstanceOf(ErrorCode::class);
    }
});

it('ships v2-read as a part of v2-write, with commands of bin/ only', function () {
    $read = apiSetCommands('v2-read');
    $write = apiSetCommands('v2-write');

    expect(array_diff($read, $write))->toBe([]);
    foreach ($write as $command) {
        expect(is_file(base_path('../bin/'.$command)))->toBeTrue("{$command} is not a command of bin/");
    }
});
