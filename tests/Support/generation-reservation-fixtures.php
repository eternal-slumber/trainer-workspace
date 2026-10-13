<?php

use App\Models\ScheduledTraining;
use App\Models\TrainingPlanGeneration;
use App\Models\User;
use Illuminate\Support\Str;

function reservationTraining(User $user, string $subjectType = 'trainee', string $status = 'planned'): ScheduledTraining
{
    $subject = $subjectType === 'trainee'
        ? $user->trainees()->create(['name' => 'Reservation fixture', 'level' => 'beginner', 'goal' => 'Coordination'])
        : $user->trainingGroups()->create([
            'name' => 'Reservation group', 'sport_type' => 'Training', 'age_range' => '10–12',
            'level' => 'beginner', 'goal' => 'Coordination',
        ]);

    return $user->scheduledTrainings()->create([
        $subjectType === 'trainee' ? 'trainee_id' : 'training_group_id' => $subject->id,
        'starts_at' => now()->addDay(), 'ends_at' => now()->addDay()->addHour(),
        'location' => 'Test room', 'status' => $status,
    ]);
}

function reservationRecord(User $user, ScheduledTraining $training, string $status = 'queued'): TrainingPlanGeneration
{
    $generation = new TrainingPlanGeneration;
    $generation->forceFill([
        'user_id' => $user->id, 'scheduled_training_id' => $training->id,
        'request_key' => (string) Str::uuid(), 'status' => $status,
        'run_token' => $status === 'running' ? (string) Str::uuid() : null,
        'lease_expires_at' => $status === 'running' ? now()->addMinutes(5) : null,
        'attempt_count' => $status === 'running' ? 1 : 0,
    ])->save();

    return $generation;
}
