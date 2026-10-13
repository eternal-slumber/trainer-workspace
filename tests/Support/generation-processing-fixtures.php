<?php

use App\Actions\TrainingPlans\ReserveTrainingPlanGeneration;
use App\Models\Exercise;
use App\Models\User;
use App\Services\AI\AiClientInterface;
use App\Services\AI\AiResponse;
use Illuminate\Support\Str;

require_once __DIR__.'/generation-reservation-fixtures.php';

function processingFixture(): array
{
    $user = User::factory()->create();
    $training = reservationTraining($user);
    $exercise = Exercise::factory()->for($user)->create();
    $payload = json_encode(['title' => 'Private generated plan', 'goal' => 'Coordination',
        'total_duration_minutes' => 60, 'ai_reasoning' => 'Private reasoning', 'warnings' => [],
        'blocks' => [['name' => 'Main block', 'duration_minutes' => 60, 'position' => 1,
            'exercises' => [['exercise_id' => $exercise->id, 'position' => 1, 'sets' => 3]]]]], JSON_THROW_ON_ERROR);
    $client = new ProcessingAiClient($payload);
    app()->instance(AiClientInterface::class, $client);
    $generation = app(ReserveTrainingPlanGeneration::class)->execute($user, $training, (string) Str::uuid());

    return [$user, $training, $exercise, $generation, $client];
}

final class ProcessingAiClient implements AiClientInterface
{
    public int $calls = 0;

    public ?Closure $onCall = null;

    public ?Throwable $exception = null;

    public function __construct(public string $content) {}

    public function chat(array $messages, array $options = []): AiResponse
    {
        $this->calls++;
        expect($options['private'])->toBeTrue();
        ($this->onCall)?->__invoke();
        if ($this->exception !== null) {
            throw $this->exception;
        }

        return new AiResponse($this->content, 'fake-model', 'fake');
    }
}
