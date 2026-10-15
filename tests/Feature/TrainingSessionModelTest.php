<?php

use App\Models\TrainingPlan;
use App\Models\TrainingPlanBlock;
use App\Models\TrainingPlanExercise;
use App\Models\TrainingSession;
use App\Models\TrainingSessionExercise;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    if (DB::connection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('Training session data constraints require PostgreSQL.');
    }
});

test('sessions support individual and group recipients with their relationships', function (string $type) {
    $session = sessionDataFixture($type)->fresh();
    $subject = $session->trainee ?? $session->trainingGroup;
    $exercise = $session->exercises()->sole();

    expect($session->status)->toBe('in_progress')
        ->and($session->started_at)->toBeInstanceOf(CarbonImmutable::class)
        ->and($session->completed_at)->toBeNull()
        ->and($session->comment)->toBeNull()
        ->and($session->user->trainingSessions()->sole()->is($session))->toBeTrue()
        ->and($session->scheduledTraining->trainingSession->is($session))->toBeTrue()
        ->and($session->trainingPlan->trainingSession->is($session))->toBeTrue()
        ->and($subject->trainingSessions()->sole()->is($session))->toBeTrue()
        ->and($session->getAttribute($type.'_id'))->toBe($subject->id)
        ->and($exercise->trainingSession->is($session))->toBeTrue()
        ->and($exercise->status)->toBe('pending')
        ->and($exercise->actual_weight)->toBeNull()
        ->and($exercise->actual_repetitions)->toBeNull()
        ->and($exercise->comment)->toBeNull();
})->with(['trainee', 'training_group']);

test('one session per scheduled training is enforced for every session status', function (string $status) {
    $session = sessionDataFixture();
    $session->update([
        'status' => $status,
        'completed_at' => $status === 'completed' ? $session->started_at : null,
    ]);

    expect(fn () => DB::transaction(fn () => $session->replicate()->save()))
        ->toThrow(QueryException::class, 'training_sessions_scheduled_training_id_unique');

    expect(TrainingSession::query()->count())->toBe(1);
})->with(TrainingSession::STATUSES);

test('sessions require exactly one recipient', function (bool $both) {
    $session = sessionDataFixture();
    $group = $session->user->trainingGroups()->create([
        'name' => 'Группа', 'sport_type' => 'ОФП', 'age_range' => '18–40',
        'level' => 'beginner', 'goal' => 'Выносливость',
    ]);

    expect(fn () => DB::transaction(fn () => DB::table('training_sessions')->where('id', $session->id)->update([
        'trainee_id' => $both ? $session->trainee_id : null,
        'training_group_id' => $both ? $group->id : null,
    ])))->toThrow(QueryException::class, 'training_sessions_subject_check');
})->with(['both recipients' => true, 'no recipient' => false]);

test('the database rejects invalid session statuses', function (string $status) {
    $session = sessionDataFixture();

    expect(fn () => DB::transaction(fn () => DB::table('training_sessions')->where('id', $session->id)
        ->update(['status' => $status])))
        ->toThrow(QueryException::class, 'SQLSTATE[23514]');
})->with(['pending', 'cancelled', 'unknown', '']);

test('the database rejects inconsistent session timestamps', function (array $attributes, string $constraint) {
    $session = sessionDataFixture();

    expect(fn () => DB::transaction(fn () => DB::table('training_sessions')->where('id', $session->id)
        ->update($attributes)))
        ->toThrow(QueryException::class, $constraint);
})->with([
    'completed without completion time' => [
        ['status' => 'completed'], 'training_sessions_completion_check',
    ],
    'in progress with completion time' => [
        ['completed_at' => '2026-10-15 10:00:00+00'], 'training_sessions_completion_check',
    ],
    'completion before start' => [
        ['status' => 'completed', 'completed_at' => '2026-10-15 08:59:59+00'], 'training_sessions_time_check',
    ],
    'missing start time' => [
        ['started_at' => null], 'started_at',
    ],
]);

test('completion can equal or follow start and compares timezone aware instants', function (string $completedAt) {
    $session = sessionDataFixture();
    DB::table('training_sessions')->where('id', $session->id)->update([
        'status' => 'completed', 'completed_at' => $completedAt,
    ]);
    $session->refresh();

    expect($session->status)->toBe('completed')
        ->and($session->completed_at)->toBeInstanceOf(CarbonImmutable::class)
        ->and($session->completed_at->gte($session->started_at))->toBeTrue();
})->with(['2026-10-15 12:00:00+03', '2026-10-15 10:00:00+00']);

test('all exercise statuses allow omitted actual results', function (string $status) {
    $exercise = sessionDataFixture()->exercises()->sole();
    $exercise->update(['status' => $status]);
    $exercise->refresh();

    expect($exercise->status)->toBe($status)
        ->and($exercise->actual_weight)->toBeNull()
        ->and($exercise->actual_repetitions)->toBeNull()
        ->and($exercise->comment)->toBeNull();
})->with(TrainingSessionExercise::STATUSES);

test('the database rejects invalid exercise statuses', function (string $status) {
    $exercise = sessionDataFixture()->exercises()->sole();

    expect(fn () => DB::transaction(fn () => DB::table('training_session_exercises')->where('id', $exercise->id)
        ->update(['status' => $status])))
        ->toThrow(QueryException::class, 'training_session_exercises_status_check');
})->with(['in_progress', 'draft', 'unknown', '']);

test('actual weight preserves null zero and decimal values', function (?string $weight, ?string $expected) {
    $exercise = sessionDataFixture()->exercises()->sole();
    $exercise->update(['actual_weight' => $weight]);

    expect($exercise->fresh()->actual_weight)->toBe($expected);
})->with([[null, null], ['0', '0.00'], ['25.50', '25.50'], ['999999.99', '999999.99']]);

test('the database rejects negative and non numeric weights', function (string $weight) {
    $exercise = sessionDataFixture()->exercises()->sole();

    expect(fn () => DB::transaction(fn () => DB::table('training_session_exercises')->where('id', $exercise->id)
        ->update(['actual_weight' => $weight])))
        ->toThrow(QueryException::class, 'training_session_exercises_weight_check');
})->with(['-0.01', '-10', 'NaN']);

test('exercise results and comments can be recorded without changing snapshots', function () {
    $session = sessionDataFixture();
    $exercise = $session->exercises()->sole();
    $snapshot = $exercise->snapshot;
    $sessionSnapshot = $session->snapshot;

    $session->update(['comment' => 'Хорошо перенёс нагрузку.']);
    $exercise->update([
        'status' => 'completed', 'actual_weight' => '25.50',
        'actual_repetitions' => '10/10/8', 'comment' => 'Последний подход с поддержкой.',
    ]);

    expect($exercise->fresh()->snapshot)->toEqual($snapshot)
        ->and($exercise->fresh()->actual_repetitions)->toBe('10/10/8')
        ->and($exercise->fresh()->comment)->toBe('Последний подход с поддержкой.')
        ->and($session->fresh()->snapshot)->toEqual($sessionSnapshot)
        ->and($session->fresh()->comment)->toBe('Хорошо перенёс нагрузку.');
});

test('exercise order is unique within a block and sorted across the session', function () {
    $session = sessionDataFixture();
    $first = $session->exercises()->sole();
    $last = $first->replicate()->forceFill(['block_position' => 2, 'position' => 2]);
    $last->save();
    $second = $first->replicate()->forceFill(['position' => 2]);
    $second->save();
    $third = $first->replicate()->forceFill(['block_position' => 2]);
    $third->save();

    expect($session->exercises()->pluck('id')->all())->toBe([$first->id, $second->id, $third->id, $last->id]);

    expect(fn () => DB::transaction(fn () => $first->replicate()->save()))
        ->toThrow(QueryException::class, 'training_session_exercises_position_unique');

    expect(fn () => DB::transaction(fn () => DB::table('training_session_exercises')->where('id', $third->id)
        ->update(['block_position' => 1])))
        ->toThrow(QueryException::class, 'training_session_exercises_position_unique');

    expect(sessionDataFixture()->exercises()->sole()->position)->toBe(1);
});

test('exercise positions must be positive', function (string $column, int $value) {
    $exercise = sessionDataFixture()->exercises()->sole();

    expect(fn () => DB::transaction(fn () => DB::table('training_session_exercises')->where('id', $exercise->id)
        ->update([$column => $value])))
        ->toThrow(QueryException::class, 'training_session_exercises_position_check');
})->with([['block_position', 0], ['block_position', -1], ['position', 0], ['position', -1]]);

test('snapshots round trip as JSONB with recipient plan block and exercise metadata', function (string $type) {
    $session = sessionDataFixture($type);
    $exercise = $session->exercises()->sole();
    $snapshot = $session->snapshot;
    $exerciseSnapshot = $exercise->snapshot;

    expect(Schema::getColumnType('training_sessions', 'snapshot'))->toBe('jsonb')
        ->and(Schema::getColumnType('training_session_exercises', 'snapshot'))->toBe('jsonb')
        ->and($session->fresh()->snapshot)->toEqual($snapshot)
        ->and($exercise->fresh()->snapshot)->toEqual($exerciseSnapshot)
        ->and($snapshot['subject'])->toBe(['type' => $type, 'name' => 'Получатель занятия'])
        ->and($snapshot['plan']['title'])->toBe('Силовая тренировка')
        ->and($snapshot['plan']['goal'])->toBe('Развить силу')
        ->and($snapshot['plan']['blocks'][0])->toEqual([
            'name' => 'Основная часть', 'duration_minutes' => 40,
            'position' => 1, 'notes' => 'Контролировать технику',
        ])
        ->and($exerciseSnapshot)->toEqual([
            'exercise_id' => null, 'name' => 'Приседания', 'description' => 'Спина прямая',
            'duration_minutes' => 10, 'sets' => 3, 'repetitions' => '8–12',
            'rest_seconds' => 60, 'notes' => 'Без боли',
        ]);

    $keys = Schema::getForeignKeys('training_session_exercises');
    expect($keys)->toHaveCount(1)
        ->and($keys[0]['foreign_table'])->toBe('training_sessions');
})->with(['trainee', 'training_group']);

test('source edits and replacement of plan exercises do not change session history', function (string $type) {
    $session = sessionDataFixture($type);
    $plan = $session->trainingPlan;
    $sourceExercise = $plan->blocks()->sole()->exercises()->sole();
    $exercise = $session->exercises()->sole();
    $exercise->update(['actual_weight' => '25.50', 'actual_repetitions' => '10/10/8']);
    $snapshot = $session->snapshot;
    $exerciseBefore = $exercise->fresh()->getAttributes();

    ($session->trainee ?? $session->trainingGroup)->update(['name' => 'Новое имя']);
    $session->scheduledTraining->update(['location' => 'Другой зал']);
    $plan->update(['title' => 'Другой план', 'goal' => 'Другая цель']);
    $sourceExercise->update(['name' => 'Другое упражнение', 'sets' => 5, 'repetitions' => '20']);
    $plan->blocks()->delete();
    TrainingPlanBlock::factory()->for($plan)->create(['name' => 'Новый блок']);

    $this->assertModelMissing($sourceExercise);
    expect($session->fresh()->snapshot)->toEqual($snapshot)
        ->and($exercise->fresh()->getAttributes())->toBe($exerciseBefore);
})->with(['trainee', 'training_group']);

test('deleting a source plan preserves snapshots actual results and recipient relationships', function (string $type) {
    $session = sessionDataFixture($type);
    $session->update([
        'status' => 'completed', 'completed_at' => $session->started_at->addHour(),
        'comment' => 'Занятие завершено',
    ]);
    $plan = $session->trainingPlan;
    $exercise = $session->exercises()->sole();
    $exercise->update(['status' => 'completed', 'actual_weight' => '25.50', 'comment' => 'Выполнено']);
    $snapshot = $session->snapshot;
    $sessionBefore = $session->fresh()->getAttributes();
    $sessionBefore['training_plan_id'] = null;
    $exerciseBefore = $exercise->fresh()->getAttributes();

    $plan->delete();
    $session->refresh();

    $this->assertModelMissing($plan);
    expect($session->training_plan_id)->toBeNull()
        ->and($session->trainingPlan)->toBeNull()
        ->and($session->snapshot)->toEqual($snapshot)
        ->and($session->getAttributes())->toBe($sessionBefore)
        ->and($session->exercises()->sole()->getAttributes())->toBe($exerciseBefore)
        ->and(($session->trainee ?? $session->trainingGroup)->trainingSessions()->sole()->is($session))->toBeTrue();
    $this->assertModelExists($session->scheduledTraining);
})->with(['trainee', 'training_group']);

test('sessions without a plan can store a result without exercises', function () {
    $session = sessionDataFixture(withPlan: false);
    $session->update(['status' => 'completed', 'completed_at' => $session->started_at->addHour()]);
    $session->refresh();

    expect($session->training_plan_id)->toBeNull()
        ->and($session->snapshot['plan'])->toBeNull()
        ->and($session->exercises()->count())->toBe(0)
        ->and($session->status)->toBe('completed');
});

test('deleting a schedule preserves sessions when its plan is also deleted by cascade', function () {
    $session = sessionDataFixture();
    $snapshot = $session->snapshot;
    $exerciseBefore = $session->exercises()->sole()->getAttributes();
    $session->scheduledTraining->delete();
    $session->refresh();

    expect($session->scheduled_training_id)->toBeNull()
        ->and($session->training_plan_id)->toBeNull()
        ->and($session->snapshot)->toEqual($snapshot)
        ->and($session->exercises()->sole()->getAttributes())->toBe($exerciseBefore);
});

test('session history prevents physical recipient deletion after detaching the schedule', function (string $type) {
    $session = sessionDataFixture($type);
    $subject = $session->trainee ?? $session->trainingGroup;
    $session->scheduledTraining->delete();
    $subject->archive();

    expect(fn () => DB::transaction(fn () => $subject->delete()))
        ->toThrow(QueryException::class, 'training_sessions_'.$type.'_id_foreign');

    $this->assertModelExists($session);
    $this->assertModelExists($subject);
    expect($subject->fresh()->trainingSessions()->sole()->is($session))->toBeTrue();
})->with(['trainee', 'training_group']);

test('account deletion still cascades sessions and their exercises', function (string $type) {
    $session = sessionDataFixture($type);
    $exercise = $session->exercises()->sole();
    $session->user->delete();

    $this->assertModelMissing($session);
    $this->assertModelMissing($exercise);
})->with(['trainee', 'training_group']);

function sessionDataFixture(string $type = 'trainee', bool $withPlan = true): TrainingSession
{
    $user = User::factory()->create();
    $subject = $type === 'trainee'
        ? $user->trainees()->create(['name' => 'Получатель занятия', 'level' => 'beginner', 'goal' => 'ОФП'])
        : $user->trainingGroups()->create([
            'name' => 'Получатель занятия', 'sport_type' => 'ОФП', 'age_range' => '18–40',
            'level' => 'beginner', 'goal' => 'ОФП',
        ]);
    $training = $user->scheduledTrainings()->create([
        $type.'_id' => $subject->id, 'starts_at' => '2026-10-15 09:00:00',
        'ends_at' => '2026-10-15 10:00:00', 'location' => 'Зал',
    ]);
    $session = new TrainingSession(['started_at' => '2026-10-15 09:00:00']);
    $snapshot = [
        'subject' => ['type' => $type, 'name' => $subject->name],
        'scheduled_training' => [
            'starts_at' => $training->starts_at->toIso8601String(),
            'ends_at' => $training->ends_at->toIso8601String(), 'location' => $training->location,
        ],
        'plan' => null,
    ];
    $plan = null;
    $exercise = null;

    if ($withPlan) {
        $plan = TrainingPlan::factory()->for($user)->for($training, 'scheduledTraining')->create([
            $type.'_id' => $subject->id, 'title' => 'Силовая тренировка', 'goal' => 'Развить силу',
        ]);
        $block = TrainingPlanBlock::factory()->for($plan)->create([
            'name' => 'Основная часть', 'duration_minutes' => 40, 'notes' => 'Контролировать технику',
        ]);
        $exercise = TrainingPlanExercise::factory()->for($block)->create([
            'name' => 'Приседания', 'description' => 'Спина прямая', 'duration_minutes' => 10,
            'sets' => 3, 'repetitions' => '8–12', 'rest_seconds' => 60, 'notes' => 'Без боли',
        ]);
        $snapshot['plan'] = [
            ...$plan->only(['title', 'goal', 'total_duration_minutes', 'notes']),
            'blocks' => [$block->only(['name', 'duration_minutes', 'position', 'notes'])],
        ];
    }

    $session->forceFill([
        'scheduled_training_id' => $training->id, 'training_plan_id' => $plan?->id,
        $type.'_id' => $subject->id, 'snapshot' => $snapshot,
    ]);
    $user->trainingSessions()->save($session);

    if ($exercise !== null) {
        $session->exercises()->save((new TrainingSessionExercise)->forceFill([
            'block_position' => 1, 'position' => 1,
            'snapshot' => $exercise->only([
                'exercise_id', 'name', 'description', 'duration_minutes',
                'sets', 'repetitions', 'rest_seconds', 'notes',
            ]),
        ]));
    }

    return $session;
}
