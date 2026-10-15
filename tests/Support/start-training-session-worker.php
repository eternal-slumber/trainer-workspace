<?php

use App\Actions\TrainingPlans\CompleteTrainingPlanAction;
use App\Actions\TrainingPlans\ReserveTrainingPlanGeneration;
use App\Actions\TrainingPlans\SaveTrainingPlan;
use App\Actions\TrainingSessions\StartTrainingSessionAction;
use App\Models\ScheduledTraining;
use App\Models\TrainingPlanGeneration;
use App\Models\User;
use App\Services\Agent\TrainingAgentService;
use App\Services\AI\AiClientInterface;
use App\Services\AI\AiResponse;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

$root = dirname(__DIR__, 2);
require $root.'/vendor/autoload.php';
require __DIR__.'/training-session-fixtures.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (DB::connection()->getDriverName() !== 'pgsql'
    || ! preg_match('/^testing(?:_test_\d+)?$/', DB::connection()->getDatabaseName())) {
    throw new LogicException('Session concurrency workers require a PostgreSQL testing database.');
}

[$script, $userId, $trainingId, $operation, $name] = $argv;
DB::select("select set_config('application_name', ?, false)", [$name]);
Http::preventStrayRequests();
$app->instance(AiClientInterface::class, new class implements AiClientInterface
{
    public function chat(array $messages, array $options = []): AiResponse
    {
        throw new LogicException('Concurrency tests must not call an AI provider.');
    }
});
$user = User::query()->findOrFail((int) $userId);
$training = ScheduledTraining::query()->findOrFail((int) $trainingId);

try {
    $result = match ($operation) {
        'start' => app(StartTrainingSessionAction::class)->execute($user, $training),
        'manual' => app(SaveTrainingPlan::class)->create($user, $training, sessionStartPlanAttributes()),
        'reserve' => app(ReserveTrainingPlanGeneration::class)->execute($user, $training, (string) Str::uuid()),
        'complete' => app(CompleteTrainingPlanAction::class)->execute($user, $training->trainingPlan()->sole()),
        'publish' => app(TrainingAgentService::class)->processGeneration(
            TrainingPlanGeneration::query()->where('scheduled_training_id', $training->id)->sole()->id,
        ),
        'reserve-and-publish' => app(TrainingAgentService::class)->processGeneration(
            sessionStartCachedGeneration($user, $training)->id,
        ),
        'cancel', 'complete-without-plan' => (function () use ($app, $user, $training, $operation): array {
            Auth::guard()->setUser($user);
            $request = Request::create('/scheduled-trainings/'.$training->id, 'PATCH', [
                'status' => $operation === 'cancel' ? 'cancelled' : 'completed',
            ], server: ['HTTP_ACCEPT' => 'application/json']);
            $kernel = $app->make(HttpKernel::class);
            $response = $kernel->handle($request);
            $kernel->terminate($request, $response);

            return ['status' => $response->getStatusCode()];
        })(),
        default => throw new LogicException('Unknown concurrency operation.'),
    };
    echo json_encode(is_array($result) ? $result : ['status' => 200, 'id' => is_object($result) ? $result->id : null], JSON_THROW_ON_ERROR);
} catch (ValidationException $exception) {
    echo json_encode(['status' => 422, 'errors' => array_keys($exception->errors())], JSON_THROW_ON_ERROR);
}
