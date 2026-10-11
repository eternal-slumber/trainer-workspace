<?php

use App\Http\Requests\StoreScheduledTrainingRequest;
use App\Models\AgentMemory;
use App\Models\Trainee;
use App\Models\TrainingGroup;
use App\Models\TrainingNote;
use App\Models\TrainingPlan;
use App\Models\TrainingPlanBlock;
use App\Models\TrainingPlanExercise;
use App\Models\User;
use App\Services\Agent\TrainingContextBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;

dataset('archive subject types', [
    'client' => ['trainee'],
    'group' => ['training_group'],
]);

afterEach(function () {
    Date::setTestNow();
});

test('a subject without history is archived instead of deleted', function (string $type) {
    $user = User::factory()->create();
    $subject = createArchivingSubject($user, $type);

    $this->actingAs($user)
        ->delete(route(archivingRoutePrefix($type).'.destroy', $subject))
        ->assertRedirect(route(archivingRoutePrefix($type).'.index'));

    $this->assertModelExists($subject);
    expect($subject->fresh()->archived_at)->not->toBeNull()
        ->and($subject->fresh()->name)->toBe($subject->name);
})->with('archive subject types');

test('archiving preserves every dependent record and all history views', function (string $type) {
    $user = User::factory()->create();
    $subject = createArchivingSubject($user, $type);
    $history = createArchivingHistory($user, $subject);
    $before = array_map(fn ($model): array => $model->fresh()->getAttributes(), $history);

    $this->actingAs($user)
        ->delete(route(archivingRoutePrefix($type).'.destroy', $subject), ['force' => true])
        ->assertRedirect(route(archivingRoutePrefix($type).'.index'));

    foreach ($history as $key => $model) {
        $this->assertModelExists($model);
        expect($model->fresh()->getAttributes())->toBe($before[$key]);
    }

    $prop = $type === 'trainee' ? 'trainee' : 'trainingGroup';
    $this->get(route(archivingRoutePrefix($type).'.show', $subject))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where("{$prop}.id", $subject->id)
            ->where("{$prop}.archived_at", fn ($value): bool => $value !== null)
            ->where('trainingHistory.0.id', $history['plan']->id)
            ->where('trainingHistory.0.training_note.note', $history['note']->note)
            ->where('agentMemories.0.id', $history['memory']->id));
    $this->get(route('training-plans.show', $history['plan']))->assertOk();
    $this->get(route('scheduled-trainings.show', $history['training']))->assertOk();
    $this->get(route('calendar'))->assertOk();

    $context = app(TrainingContextBuilder::class)->buildForScheduledTraining($user, $history['training']);
    expect($context->target->id)->toBe($subject->id)
        ->and($context->history)->toHaveCount(1)
        ->and($context->memories)->toHaveCount(1);
})->with('archive subject types');

test('active lists and archive lists are separate and owner scoped', function (string $type) {
    $user = User::factory()->create();
    $active = createArchivingSubject($user, $type);
    $archived = createArchivingSubject($user, $type);
    $archived->archive();
    $foreign = createArchivingSubject(User::factory()->create(), $type);
    $foreign->archive();
    $prop = $type === 'trainee' ? 'trainees' : 'trainingGroups';

    $this->actingAs($user)->get(route(archivingRoutePrefix($type).'.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('archived', false)
            ->has($prop, 1)
            ->where("{$prop}.0.id", $active->id));
    $this->get(route(archivingRoutePrefix($type).'.index', ['archived' => 1]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('archived', true)
            ->has($prop, 1)
            ->where("{$prop}.0.id", $archived->id));
    $this->get(route('scheduled-trainings.create'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has($prop, 1)
            ->where("{$prop}.0.id", $active->id));
})->with('archive subject types');

test('a subject can be restored with the same identity and history', function (string $type) {
    $user = User::factory()->create();
    $subject = createArchivingSubject($user, $type);
    $history = createArchivingHistory($user, $subject);
    $subject->archive();

    $this->actingAs($user)->post(route(archivingRoutePrefix($type).'.restore', $subject))
        ->assertRedirect(route(archivingRoutePrefix($type).'.show', $subject));

    expect($subject->fresh()->archived_at)->toBeNull()
        ->and($history['plan']->fresh()->scheduled_training_id)->toBe($history['training']->id)
        ->and($subject->trainingNotes()->sole()->id)->toBe($history['note']->id);
    $this->postJson(route('scheduled-trainings.store'), archivingTrainingAttributes($subject))
        ->assertRedirect();
    expect($subject->scheduledTrainings()->count())->toBe(2);
})->with('archive subject types');

test('new trainings and duplicates reject archived subjects', function (string $type) {
    $user = User::factory()->create();
    $subject = createArchivingSubject($user, $type);
    $training = $user->scheduledTrainings()->create(archivingTrainingAttributes($subject));
    $subject->archive();
    $column = $type.'_id';

    $this->actingAs($user)
        ->postJson(route('scheduled-trainings.store'), archivingTrainingAttributes($subject))
        ->assertUnprocessable()
        ->assertJsonValidationErrors($column);
    $this->postJson(route('scheduled-trainings.duplicate', $training))
        ->assertUnprocessable()
        ->assertJsonValidationErrors($column);

    expect($subject->scheduledTrainings()->count())->toBe(1);
})->with('archive subject types');

test('existing archived trainings remain editable but cannot switch to another archived subject', function (string $type) {
    $user = User::factory()->create();
    $subject = createArchivingSubject($user, $type);
    $other = createArchivingSubject($user, $type);
    $training = $user->scheduledTrainings()->create(archivingTrainingAttributes($subject));
    $subject->archive();
    $other->archive();
    $prop = $type === 'trainee' ? 'trainees' : 'trainingGroups';

    $this->actingAs($user)->get(route('scheduled-trainings.edit', $training))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has($prop, 1)
            ->where("{$prop}.0.id", $subject->id));
    $this->patchJson(route('scheduled-trainings.update', $training), [
        ...archivingSubjectIds($subject),
        'location' => 'Другой зал',
    ])->assertRedirect();
    $this->patchJson(route('scheduled-trainings.update', $training), archivingSubjectIds($other))
        ->assertUnprocessable()
        ->assertJsonValidationErrors($type.'_id');

    expect($training->fresh()->location)->toBe('Другой зал')
        ->and($training->fresh()->getAttribute($type.'_id'))->toBe($subject->id);
})->with('archive subject types');

test('archiving between request validation and creation prevents a new training', function (string $type) {
    $user = User::factory()->create();
    $subject = createArchivingSubject($user, $type);

    app()->afterResolving(StoreScheduledTrainingRequest::class, function () use ($subject): void {
        $subject->archive();
    });

    $this->actingAs($user)
        ->postJson(route('scheduled-trainings.store'), archivingTrainingAttributes($subject))
        ->assertUnprocessable()
        ->assertJsonValidationErrors($type.'_id');

    expect($subject->scheduledTrainings()->count())->toBe(0);
})->with('archive subject types');

test('a user cannot archive or restore another users subject', function (string $type) {
    $user = User::factory()->create();
    $subject = createArchivingSubject(User::factory()->create(), $type);
    $subject->archive();
    $before = $subject->fresh()->getAttributes();

    $this->actingAs($user)->delete(route(archivingRoutePrefix($type).'.destroy', $subject))
        ->assertNotFound();
    $this->post(route(archivingRoutePrefix($type).'.restore', $subject))->assertNotFound();

    expect($subject->fresh()->getAttributes())->toBe($before);
})->with('archive subject types');

test('guests cannot archive or restore a subject', function (string $type) {
    $subject = createArchivingSubject(User::factory()->create(), $type);
    $this->delete(route(archivingRoutePrefix($type).'.destroy', $subject))->assertRedirect(route('login'));
    $this->post(route(archivingRoutePrefix($type).'.restore', $subject))->assertRedirect(route('login'));

    expect($subject->fresh()->archived_at)->toBeNull();
})->with('archive subject types');

test('repeated archive and restore requests are idempotent', function (string $type) {
    $user = User::factory()->create();
    $subject = createArchivingSubject($user, $type);
    Date::setTestNow('2026-10-09 12:00:00');
    $this->actingAs($user)->delete(route(archivingRoutePrefix($type).'.destroy', $subject))->assertRedirect();
    $before = $subject->fresh()->getAttributes();
    Date::setTestNow('2026-10-09 13:00:00');
    $this->delete(route(archivingRoutePrefix($type).'.destroy', $subject))->assertRedirect();
    expect($subject->fresh()->getAttributes())->toBe($before);

    $this->post(route(archivingRoutePrefix($type).'.restore', $subject))->assertRedirect();
    $restored = $subject->fresh()->getAttributes();
    Date::setTestNow('2026-10-09 14:00:00');
    $this->post(route(archivingRoutePrefix($type).'.restore', $subject))->assertRedirect();
    expect($subject->fresh()->getAttributes())->toBe($restored);
})->with('archive subject types');

test('ordinary subject edits cannot bypass restoration', function (string $type) {
    $user = User::factory()->create();
    $subject = createArchivingSubject($user, $type);
    $subject->archive();
    $attributes = $subject->only(['name', 'age', 'level', 'goal', 'restrictions', 'notes', 'sport_type', 'age_range']);
    $this->actingAs($user)->patch(route(archivingRoutePrefix($type).'.update', $subject), [
        ...$attributes,
        'archived_at' => null,
    ])->assertRedirect();

    expect($subject->fresh()->archived_at)->not->toBeNull();
})->with('archive subject types');

test('the database prevents physical subject deletion from cascading history', function (string $type) {
    $user = User::factory()->create();
    $subject = createArchivingSubject($user, $type);
    $history = createArchivingHistory($user, $subject);
    $rejected = false;

    try {
        DB::transaction(fn () => DB::table($subject->getTable())->where('id', $subject->id)->delete());
    } catch (QueryException $exception) {
        $rejected = true;

        if (DB::connection()->getDriverName() === 'pgsql') {
            expect($exception->errorInfo[0])->toBe('23503');
        }
    }

    expect($rejected)->toBeTrue();
    $this->assertModelExists($subject);

    foreach ($history as $model) {
        $this->assertModelExists($model);
    }
})->with('archive subject types');

test('all subject foreign keys preserve their parents without delete actions', function () {
    foreach (['scheduled_trainings', 'training_plans', 'training_notes', 'agent_memories'] as $table) {
        $keys = collect(Schema::getForeignKeys($table))->filter(
            fn (array $key): bool => in_array($key['columns'], [['trainee_id'], ['training_group_id']], true),
        );

        expect($keys)->toHaveCount(2);

        foreach ($keys as $key) {
            expect(strtolower($key['on_delete']))->toBe('no action');
        }
    }
});

test('explicit account deletion retains its existing semantics with archived history', function (string $type) {
    $user = User::factory()->create();
    $subject = createArchivingSubject($user, $type);
    $history = createArchivingHistory($user, $subject);
    $subject->archive();

    $this->actingAs($user)->delete(route('profile.destroy'), ['password' => 'password'])
        ->assertRedirect('/');

    $this->assertModelMissing($user);
    $this->assertModelMissing($subject);

    foreach ($history as $model) {
        $this->assertModelMissing($model);
    }
})->with('archive subject types');

function archivingRoutePrefix(string $type): string
{
    return $type === 'trainee' ? 'trainees' : 'training-groups';
}

function createArchivingSubject(User $user, string $type): Trainee|TrainingGroup
{
    return $type === 'trainee'
        ? $user->trainees()->create(['name' => 'Клиент архива', 'level' => 'beginner', 'goal' => 'ОФП'])
        : $user->trainingGroups()->create([
            'name' => 'Группа архива', 'sport_type' => 'ОФП', 'age_range' => '18–30',
            'level' => 'beginner', 'goal' => 'ОФП',
        ]);
}

/** @return array<string, int|null> */
function archivingSubjectIds(Trainee|TrainingGroup $subject): array
{
    return [
        'trainee_id' => $subject instanceof Trainee ? $subject->id : null,
        'training_group_id' => $subject instanceof TrainingGroup ? $subject->id : null,
    ];
}

/** @return array<string, mixed> */
function archivingTrainingAttributes(Trainee|TrainingGroup $subject): array
{
    return [
        ...archivingSubjectIds($subject),
        'starts_at' => '2026-07-09T18:00:00Z', 'ends_at' => '2026-07-09T19:00:00Z',
        'location' => 'Зал архива', 'status' => 'planned', 'color' => 'blue',
    ];
}

/** @return array<string, Model> */
function createArchivingHistory(User $user, Trainee|TrainingGroup $subject): array
{
    $ids = ['user_id' => $user->id, ...archivingSubjectIds($subject)];
    $training = $user->scheduledTrainings()->create([
        ...archivingTrainingAttributes($subject), 'status' => 'completed',
    ]);
    $plan = TrainingPlan::factory()->create([
        ...$ids, 'scheduled_training_id' => $training->id, 'status' => 'completed',
    ]);
    $block = TrainingPlanBlock::factory()->for($plan)->create();
    $exercise = TrainingPlanExercise::factory()->for($block)->create();
    $note = TrainingNote::factory()->create([...$ids, 'training_plan_id' => $plan->id]);
    $memory = AgentMemory::factory()->create($ids);

    return compact('training', 'plan', 'block', 'exercise', 'note', 'memory');
}
