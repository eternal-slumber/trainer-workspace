<?php

use App\Models\Exercise;
use App\Models\TrainingPlanGeneration;
use App\Services\Agent\TrainingAgentService;
use App\Services\AI\AiClientInterface;
use App\Services\AI\AiResponse;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

$root = dirname(__DIR__, 2);
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (DB::connection()->getDatabaseName() !== 'testing') {
    throw new LogicException('Use only testing.');
}
[$script, $id, $mode, $directory] = $argv;
if ($mode === 'crash-before-send') {
    TrainingPlanGeneration::retrieved(function (TrainingPlanGeneration $generation) use ($directory): void {
        if ($generation->status === 'running' && $generation->request_started_at === null) {
            file_put_contents($directory.'/ready', 'ready');
            exit(71);
        }
    });
}
if ($mode === 'crash-after-checkpoint') {
    TrainingPlanGeneration::updated(function (TrainingPlanGeneration $generation): void {
        if ($generation->response_received_at !== null && $generation->status === 'running') {
            DB::afterCommit(fn () => exit(73));
        }
    });
}
$app->instance(AiClientInterface::class, new class($mode, $directory) implements AiClientInterface
{
    public function __construct(private string $mode, private string $directory) {}

    public function chat(array $messages, array $options = []): AiResponse
    {
        if (DB::transactionLevel() !== 0) {
            throw new LogicException('LLM called inside a transaction.');
        }
        file_put_contents($this->directory.'/calls', "call\n", FILE_APPEND | LOCK_EX);
        file_put_contents($this->directory.'/ready', 'ready');
        if ($this->mode === 'crash-after-send') {
            exit(72);
        }
        if ($this->mode === 'hold') {
            $deadline = microtime(true) + 10;
            while (! is_file($this->directory.'/release')) {
                if (microtime(true) > $deadline) {
                    throw new LogicException('Release timed out.');
                }
                usleep(10000);
            }
        }

        return new AiResponse(json_encode(['title' => 'Concurrent generated plan', 'goal' => 'Coordination',
            'total_duration_minutes' => 60, 'ai_reasoning' => null, 'warnings' => [],
            'blocks' => [['name' => 'Main', 'duration_minutes' => 60, 'position' => 1,
                'exercises' => [['exercise_id' => Exercise::query()->sole()->id, 'position' => 1]]]]], JSON_THROW_ON_ERROR), 'fake', 'fake');
    }
});
$delay = $app->make(TrainingAgentService::class)->processGeneration($id);
echo json_encode(['delay' => $delay, 'status' => TrainingPlanGeneration::query()->find($id)?->status]);
