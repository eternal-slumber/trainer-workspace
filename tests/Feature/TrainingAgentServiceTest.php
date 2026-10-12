<?php

use App\Models\Exercise;
use App\Models\ScheduledTraining;
use App\Models\Trainee;
use App\Models\TrainingGroup;
use App\Models\TrainingPlan;
use App\Models\TrainingPlanBlock;
use App\Models\TrainingPlanExercise;
use App\Models\User;
use App\Services\Agent\DTO\TrainingContext;
use App\Services\Agent\DTO\ValidatedTrainingPlan;
use App\Services\Agent\DTO\ValidatedTrainingPlanBlock;
use App\Services\Agent\DTO\ValidatedTrainingPlanExercise;
use App\Services\Agent\TrainingAgentService;
use App\Services\Agent\TrainingPlanAlreadyExistsException;
use App\Services\Agent\TrainingPlanValidator;
use App\Services\AI\AiClientException;
use App\Services\AI\AiClientInterface;
use App\Services\AI\AiMessage;
use App\Services\AI\AiResponse;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Assert;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\post;

uses(RefreshDatabase::class);

beforeEach(function () {
    Config::set('ai.provider', 'openrouter');
});

test('ai service creates a draft group plan with blocks and exercises', function () {
    $user = User::factory()->create();
    $trainingGroup = createAgentTrainingGroup($user);
    $scheduledTraining = createAgentScheduledTraining($user, trainingGroup: $trainingGroup);
    $exercise = Exercise::factory()->for($user)->create([
        'name' => 'Суставная разминка',
        'description' => 'Каноническое описание из базы',
    ]);
    fakeAgentResponse(validAgentPlanJson($exercise));

    actingAs($user);
    $response = post(route('scheduled-trainings.generate-training-plan', $scheduledTraining));

    $trainingPlan = TrainingPlan::query()->sole()->load('blocks.exercises');

    $response->assertRedirect(route('training-plans.show', $trainingPlan));
    expect($trainingPlan->user->is($user))->toBeTrue()
        ->and($trainingPlan->scheduled_training_id)->toBe($scheduledTraining->id)
        ->and($trainingPlan->training_group_id)->toBe($trainingGroup->id)
        ->and($trainingPlan->trainee_id)->toBeNull()
        ->and($trainingPlan->status)->toBe('draft')
        ->and($trainingPlan->source)->toBe('ai')
        ->and($trainingPlan->ai_reasoning)->toBe('План учитывает ограничения и прошлые заметки.')
        ->and($trainingPlan->warnings)->toBe(['Без длительных прыжков'])
        ->and($trainingPlan->blocks)->toHaveCount(1)
        ->and($trainingPlan->blocks[0]->exercises)->toHaveCount(1)
        ->and($trainingPlan->blocks[0]->exercises[0]->exercise_id)->toBe($exercise->id)
        ->and($trainingPlan->blocks[0]->exercises[0]->name)->toBe('Суставная разминка')
        ->and($trainingPlan->blocks[0]->exercises[0]->description)
        ->toBe('Каноническое описание из базы');
});

test('ai plan inherits its trainee', function () {
    $user = User::factory()->create();
    $trainee = createAgentTrainee($user);
    $scheduledTraining = createAgentScheduledTraining($user, trainee: $trainee);
    $exercise = Exercise::factory()->system()->create();
    fakeAgentResponse(validAgentPlanJson($exercise));

    $trainingPlan = app(TrainingAgentService::class)
        ->generatePlanForScheduledTraining($user, $scheduledTraining);

    expect($trainingPlan->trainee_id)->toBe($trainee->id)
        ->and($trainingPlan->training_group_id)->toBeNull()
        ->and($trainingPlan->source)->toBe('ai');
});

test('lm studio generation uses text response format', function () {
    Config::set('ai.provider', 'lmstudio');

    $user = User::factory()->create();
    $trainingGroup = createAgentTrainingGroup($user);
    $scheduledTraining = createAgentScheduledTraining($user, trainingGroup: $trainingGroup);
    $exercise = Exercise::factory()->system()->create();
    fakeAgentResponse(validAgentPlanJson($exercise), ['type' => 'text']);

    $trainingPlan = app(TrainingAgentService::class)
        ->generatePlanForScheduledTraining($user, $scheduledTraining);

    expect($trainingPlan->source)->toBe('ai');
});

test('another users scheduled training cannot be generated', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $trainingGroup = createAgentTrainingGroup($otherUser);
    $scheduledTraining = createAgentScheduledTraining($otherUser, trainingGroup: $trainingGroup);

    $fakeAiClient = fakeAgentResponse('unused');

    actingAs($user);
    post(route('scheduled-trainings.generate-training-plan', $scheduledTraining))
        ->assertNotFound();

    expect($fakeAiClient->calls)->toBe(0)
        ->and(TrainingPlan::query()->count())->toBe(0);
});

test('a second plan cannot be generated for the same scheduled training', function () {
    $user = User::factory()->create();
    $trainingGroup = createAgentTrainingGroup($user);
    $scheduledTraining = createAgentScheduledTraining($user, trainingGroup: $trainingGroup);
    createExistingAgentPlan($user, $scheduledTraining);

    $fakeAiClient = fakeAgentResponse('unused');

    expect(fn () => app(TrainingAgentService::class)
        ->generatePlanForScheduledTraining($user, $scheduledTraining))
        ->toThrow(TrainingPlanAlreadyExistsException::class);

    expect($fakeAiClient->calls)->toBe(0)
        ->and(TrainingPlan::query()->count())->toBe(1);
});

test('completed trainings reject AI generation even when the supplied model is stale', function () {
    $user = User::factory()->create();
    $training = createAgentScheduledTraining($user, trainee: createAgentTrainee($user));
    ScheduledTraining::query()->whereKey($training->id)->update(['status' => 'completed']);
    $before = $training->fresh()->getAttributes();
    $client = fakeAgentResponse('unused');

    $this->actingAs($user)->postJson(route('scheduled-trainings.generate-training-plan', $training))
        ->assertUnprocessable()->assertJsonValidationErrors('scheduled_training_id');
    expect(fn () => app(TrainingAgentService::class)->generatePlanForScheduledTraining($user, $training))
        ->toThrow(ValidationException::class);

    expect($client->calls)->toBe(0)
        ->and($training->fresh()->getAttributes())->toBe($before);
    assertNoAgentPlanRecords();
});

test('AI does not persist a plan if the training is completed during the LLM call', function () {
    $user = User::factory()->create();
    $training = createAgentScheduledTraining($user, trainee: createAgentTrainee($user));
    $exercise = Exercise::factory()->system()->create();
    $transactionLevel = DB::transactionLevel();
    $completed = null;
    $client = fakeAgentResponse(validAgentPlanJson($exercise), beforeResponse: function () use (
        $training,
        $transactionLevel,
        &$completed,
    ): void {
        expect(DB::transactionLevel())->toBe($transactionLevel);
        $this->patchJson(route('scheduled-trainings.update', $training), ['status' => 'completed'])
            ->assertRedirect();
        $completed = $training->fresh()->getAttributes();
    });

    $this->actingAs($user)->postJson(route('scheduled-trainings.generate-training-plan', $training))
        ->assertUnprocessable()->assertJsonValidationErrors('scheduled_training_id');

    expect($client->calls)->toBe(1)
        ->and($completed)->not->toBeNull()
        ->and($training->fresh()->getAttributes())->toBe($completed);
    assertNoAgentPlanRecords();
});

test('AI cannot replace a manual plan completed while its response was pending', function () {
    $user = User::factory()->create();
    $training = createAgentScheduledTraining($user, trainee: createAgentTrainee($user));
    $exercise = Exercise::factory()->system()->create();
    $completed = null;
    fakeAgentResponse(validAgentPlanJson($exercise), beforeResponse: function () use ($user, $training, &$completed): void {
        $plan = createExistingAgentPlan($user, $training);
        $this->postJson(route('training-plans.complete', $plan))->assertRedirect();
        $completed = $plan->fresh()->getAttributes();
    });

    $this->actingAs($user)->postJson(route('scheduled-trainings.generate-training-plan', $training))
        ->assertUnprocessable()->assertJsonValidationErrors('scheduled_training_id');

    expect($completed)->not->toBeNull()
        ->and(TrainingPlan::query()->sole()->getAttributes())->toBe($completed)
        ->and($training->fresh()->status)->toBe('completed');
});

test('AI can still preserve preparation for a training cancelled during its response', function () {
    $user = User::factory()->create();
    $training = createAgentScheduledTraining($user, trainee: createAgentTrainee($user));
    $exercise = Exercise::factory()->system()->create();
    fakeAgentResponse(validAgentPlanJson($exercise), beforeResponse: function () use ($training): void {
        $this->patchJson(route('scheduled-trainings.update', $training), ['status' => 'cancelled'])
            ->assertRedirect();
    });

    $this->actingAs($user)->postJson(route('scheduled-trainings.generate-training-plan', $training))
        ->assertRedirect();

    expect(TrainingPlan::query()->sole()->status)->toBe('draft')
        ->and($training->fresh()->status)->toBe('cancelled');
});

test('invalid ai json does not create any plan records', function () {
    $user = User::factory()->create();
    $trainingGroup = createAgentTrainingGroup($user);
    $scheduledTraining = createAgentScheduledTraining($user, trainingGroup: $trainingGroup);
    Exercise::factory()->system()->create();
    fakeAgentResponse('{"title":');

    actingAs($user);
    post(
        route('scheduled-trainings.generate-training-plan', $scheduledTraining),
        [],
        ['HTTP_REFERER' => route('scheduled-trainings.show', $scheduledTraining)],
    )
        ->assertRedirect(route('scheduled-trainings.show', $scheduledTraining));

    assertNoAgentPlanRecords();
});

test('exercise outside the context does not create any plan records', function () {
    $user = User::factory()->create();
    $trainingGroup = createAgentTrainingGroup($user);
    $scheduledTraining = createAgentScheduledTraining($user, trainingGroup: $trainingGroup);
    $exercise = Exercise::factory()->system()->create();
    $payload = validAgentPlanPayload($exercise);
    $payload['blocks'][0]['exercises'][0]['exercise_id'] = 999999;
    fakeAgentResponse(json_encode($payload, JSON_THROW_ON_ERROR));

    actingAs($user);
    post(
        route('scheduled-trainings.generate-training-plan', $scheduledTraining),
        [],
        ['HTTP_REFERER' => route('scheduled-trainings.show', $scheduledTraining)],
    )
        ->assertRedirect(route('scheduled-trainings.show', $scheduledTraining));

    assertNoAgentPlanRecords();
});

test('ai client failure does not create a plan', function () {
    $user = User::factory()->create();
    $trainingGroup = createAgentTrainingGroup($user);
    $scheduledTraining = createAgentScheduledTraining($user, trainingGroup: $trainingGroup);
    Exercise::factory()->system()->create();

    fakeAgentFailure(new AiClientException('AI недоступен.'));

    actingAs($user);
    post(
        route('scheduled-trainings.generate-training-plan', $scheduledTraining),
        [],
        ['HTTP_REFERER' => route('scheduled-trainings.show', $scheduledTraining)],
    )
        ->assertRedirect(route('scheduled-trainings.show', $scheduledTraining));

    assertNoAgentPlanRecords();
});

test('database error rolls back a partially persisted plan', function () {
    $user = User::factory()->create();
    $trainingGroup = createAgentTrainingGroup($user);
    $scheduledTraining = createAgentScheduledTraining($user, trainingGroup: $trainingGroup);
    $exercise = Exercise::factory()->system()->create();
    fakeAgentResponse(validAgentPlanJson($exercise));

    app()->instance(
        TrainingPlanValidator::class,
        new StubTrainingPlanValidator(
            new ValidatedTrainingPlan(
                title: 'План с ошибкой записи',
                goal: 'Проверка транзакции',
                totalDurationMinutes: 60,
                aiReasoning: null,
                warnings: [],
                blocks: [
                    new ValidatedTrainingPlanBlock(
                        name: 'Основная часть',
                        durationMinutes: 60,
                        position: 1,
                        notes: null,
                        exercises: [
                            new ValidatedTrainingPlanExercise(
                                exerciseId: 999999,
                                name: 'Несуществующее упражнение',
                                description: 'Вызовет ошибку внешнего ключа',
                                durationMinutes: 60,
                                sets: null,
                                repetitions: null,
                                restSeconds: null,
                                position: 1,
                                notes: null,
                            ),
                        ],
                    ),
                ],
            ),
        ),
    );

    try {
        app(TrainingAgentService::class)
            ->generatePlanForScheduledTraining($user, $scheduledTraining);
    } catch (QueryException) {
        assertNoAgentPlanRecords();

        return;
    }

    Assert::fail('Expected a foreign key violation.');
});

function createAgentTrainingGroup(User $user): TrainingGroup
{
    return $user->trainingGroups()->create([
        'name' => 'Группа U12',
        'sport_type' => 'ОФП',
        'age_range' => '10–12',
        'level' => 'beginner',
        'goal' => 'Развитие координации',
        'restrictions' => 'Без высокой прыжковой нагрузки',
    ]);
}

function createAgentTrainee(User $user): Trainee
{
    return $user->trainees()->create([
        'name' => 'Алексей',
        'age' => 14,
        'level' => 'beginner',
        'goal' => 'Развитие координации',
    ]);
}

function createAgentScheduledTraining(
    User $user,
    ?Trainee $trainee = null,
    ?TrainingGroup $trainingGroup = null,
): ScheduledTraining {
    return $user->scheduledTrainings()->create([
        'trainee_id' => $trainee?->id,
        'training_group_id' => $trainingGroup?->id,
        'starts_at' => now()->addDay()->setTime(18, 0),
        'ends_at' => now()->addDay()->setTime(19, 0),
        'location' => 'Зал №1',
        'status' => 'planned',
        'color' => 'blue',
    ]);
}

function createExistingAgentPlan(User $user, ScheduledTraining $scheduledTraining): TrainingPlan
{
    return TrainingPlan::factory()->create([
        'user_id' => $user->id,
        'scheduled_training_id' => $scheduledTraining->id,
        'trainee_id' => $scheduledTraining->trainee_id,
        'training_group_id' => $scheduledTraining->training_group_id,
    ]);
}

/**
 * @param  array<string, mixed>  $expectedResponseFormat
 */
function fakeAgentResponse(
    string $content,
    array $expectedResponseFormat = ['type' => 'json_object'],
    ?Closure $beforeResponse = null,
): FakeTrainingAiClient {
    $fakeAiClient = new FakeTrainingAiClient(
        content: $content,
        expectedResponseFormat: $expectedResponseFormat,
        beforeResponse: $beforeResponse,
    );
    app()->instance(AiClientInterface::class, $fakeAiClient);

    return $fakeAiClient;
}

function fakeAgentFailure(Throwable $exception): FakeTrainingAiClient
{
    $fakeAiClient = new FakeTrainingAiClient(exception: $exception);
    app()->instance(AiClientInterface::class, $fakeAiClient);

    return $fakeAiClient;
}

function validAgentPlanJson(Exercise $exercise): string
{
    return json_encode(validAgentPlanPayload($exercise), JSON_THROW_ON_ERROR);
}

/** @return array<string, mixed> */
function validAgentPlanPayload(Exercise $exercise): array
{
    return [
        'title' => 'Координация и ОФП',
        'goal' => 'Развитие координации',
        'total_duration_minutes' => 60,
        'ai_reasoning' => 'План учитывает ограничения и прошлые заметки.',
        'warnings' => ['Без длительных прыжков'],
        'blocks' => [[
            'name' => 'Основная часть',
            'duration_minutes' => 60,
            'position' => 1,
            'notes' => null,
            'exercises' => [[
                'exercise_id' => $exercise->id,
                'name' => 'Название от AI игнорируется',
                'duration_minutes' => 60,
                'sets' => 3,
                'repetitions' => '10',
                'rest_seconds' => 45,
                'position' => 1,
                'notes' => 'Контролировать технику',
            ]],
        ]],
    ];
}

function assertNoAgentPlanRecords(): void
{
    expect(TrainingPlan::query()->count())->toBe(0)
        ->and(TrainingPlanBlock::query()->count())->toBe(0)
        ->and(TrainingPlanExercise::query()->count())->toBe(0);
}

final class FakeTrainingAiClient implements AiClientInterface
{
    public int $calls = 0;

    /**
     * @param  array<string, mixed>  $expectedResponseFormat
     */
    public function __construct(
        private ?string $content = null,
        private ?Throwable $exception = null,
        private array $expectedResponseFormat = ['type' => 'json_object'],
        private ?Closure $beforeResponse = null,
    ) {}

    public function chat(array $messages, array $options = []): AiResponse
    {
        $this->calls++;

        expect($messages)->toHaveCount(2)
            ->and($messages[0])->toBeInstanceOf(AiMessage::class)
            ->and($messages[0]->role)->toBe('system')
            ->and($messages[1])->toBeInstanceOf(AiMessage::class)
            ->and($messages[1]->role)->toBe('user')
            ->and($options)->toHaveKey('user_id')
            ->and($options['max_tokens'])->toBe(4096)
            ->and($options['response_format'])->toBe($this->expectedResponseFormat);

        ($this->beforeResponse)?->__invoke();

        if ($this->exception !== null) {
            throw $this->exception;
        }

        return new AiResponse(
            content: $this->content ?? '',
            model: 'test-model',
            provider: 'fake',
        );
    }
}

final class StubTrainingPlanValidator extends TrainingPlanValidator
{
    public function __construct(private ValidatedTrainingPlan $validatedPlan) {}

    public function validate(string $json, TrainingContext $context): ValidatedTrainingPlan
    {
        return $this->validatedPlan;
    }
}
