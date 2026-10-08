<?php

namespace App\Api;

use App\Arc\CommandArguments;
use App\Arc\ExitCode;
use App\Services\Support\PlanRunner;
use App\Services\WebDomains\ApplyResult;
use App\Services\WebDomains\Step;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Runs the plan of an API v2 write (E34 spec D5, D11): every command of the
 * plan, and the commands the endpoint runs after it, must be allowed by the
 * key before the first step runs; with ?dry_run=1 the answer lists the steps
 * and nothing runs; a failed step becomes the error answer.
 */
final class PlanGate
{
    public const INVALID_ARGUMENT = 'A value contains a character the command does not accept.';

    public function __construct(
        private readonly KeyPermissions $permissions,
        private readonly Request $request,
    ) {}

    public function dryRun(): bool
    {
        return $this->request->boolean('dry_run');
    }

    /**
     * @param  list<Step>  $steps
     * @param  list<string>  $also  commands the endpoint runs after the plan
     */
    public function authorize(array $steps, array $also = []): void
    {
        $missing = $this->permissions->missing([...array_map(fn (Step $step) => $step->command, $steps), ...PlanRunner::restartCommands($steps), ...$also]);
        if ($missing !== []) {
            throw ApiException::commandsRefused($missing);
        }
    }

    /**
     * Authorize the plan, then answer the dry run or run it. Null when the
     * plan ran; the caller answers with the changed resource.
     *
     * @param  list<Step>  $steps
     * @param  Closure(list<Step>): ApplyResult  $apply
     * @param  list<string>  $also
     */
    public function execute(array $steps, Closure $apply, array $also = []): ?JsonResponse
    {
        $this->authorize($steps, $also);
        if ($this->dryRun()) {
            return $this->preview($steps);
        }
        $this->assertApplied($apply($steps));

        return null;
    }

    /**
     * execute(), with a package limit the command refuses (`E_LIMIT`)
     * answered as 422 rather than 429: the client has to change its request,
     * waiting does not help (E34 batch 4 spec §5, as batch 1 does for
     * reseller quotas).
     *
     * @param  list<Step>  $steps
     * @param  Closure(list<Step>): ApplyResult  $apply
     * @param  list<string>  $also
     */
    public function executeWithLimits(array $steps, Closure $apply, array $also = []): ?JsonResponse
    {
        try {
            return $this->execute($steps, $apply, $also);
        } catch (ApiException $e) {
            if ($e->errorCode !== ErrorCode::Limit) {
                throw $e;
            }

            throw new ApiException($e->errorCode, $e->getMessage(), 422, $e->details, $e->headers, $e->existing);
        }
    }

    /** @param  list<Step>  $steps */
    public function preview(array $steps): JsonResponse
    {
        return response()->json(['data' => ['dry_run' => true, 'steps' => array_map(self::describe(...), $steps)]]);
    }

    /**
     * A failed step answers with the status of its exit code, naming the step
     * and those that ran before it. A step whose argument was refused before
     * it ran (a line break, a NUL byte) is the client's input: 422 E_INVALID.
     */
    public function assertApplied(ApplyResult $result): void
    {
        if ($result->ok()) {
            return;
        }
        $exit = ExitCode::tryFrom($result->exitCode);

        throw new ApiException(
            $result->invalidArgument ? ErrorCode::Invalid : ErrorCode::fromExit($exit),
            $result->invalidArgument ? self::INVALID_ARGUMENT : $result->error,
            $result->invalidArgument ? 422 : ErrorCode::statusOfExit($exit),
            [
                'step' => $result->failed !== null ? self::describe($result->failed, withArgs: false) : null,
                'applied' => array_map(fn (Step $step) => self::describe($step, withArgs: false), $result->applied),
            ],
        );
    }

    /** @return array{label: string, command: string, args?: list<string>} */
    public static function describe(Step $step, bool $withArgs = true): array
    {
        $row = ['label' => $step->label, 'command' => $step->command];
        if ($withArgs) {
            $row['args'] = CommandArguments::mask($step->args);
        }

        return $row;
    }
}
