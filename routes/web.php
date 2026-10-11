<?php

use App\Http\Controllers\AgentMemoryController;
use App\Http\Controllers\CalendarController;
use App\Http\Controllers\CompleteTrainingPlanController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DuplicateScheduledTrainingController;
use App\Http\Controllers\ExerciseController;
use App\Http\Controllers\GenerateTrainingPlanController;
use App\Http\Controllers\RescheduleScheduledTrainingController;
use App\Http\Controllers\ScheduledTrainingController;
use App\Http\Controllers\TraineeController;
use App\Http\Controllers\TrainingGroupController;
use App\Http\Controllers\TrainingNoteController;
use App\Http\Controllers\TrainingPlanController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', [DashboardController::class, 'index'])->name('dashboard');
    Route::get('calendar', CalendarController::class)->name('calendar');
    Route::patch(
        'scheduled-trainings/{scheduled_training}/schedule',
        RescheduleScheduledTrainingController::class,
    )->name('scheduled-trainings.schedule');
    Route::post(
        'scheduled-trainings/{scheduled_training}/duplicate',
        DuplicateScheduledTrainingController::class,
    )->name('scheduled-trainings.duplicate');
    Route::post(
        'scheduled-trainings/{scheduled_training}/generate-training-plan',
        GenerateTrainingPlanController::class,
    )->name('scheduled-trainings.generate-training-plan');
    Route::resource('trainees', TraineeController::class);
    Route::post('trainees/{trainee}/restore', [TraineeController::class, 'restore'])->name('trainees.restore');
    Route::resource('training-groups', TrainingGroupController::class);
    Route::post('training-groups/{training_group}/restore', [TrainingGroupController::class, 'restore'])
        ->name('training-groups.restore');
    Route::resource('scheduled-trainings', ScheduledTrainingController::class);
    Route::resource('exercises', ExerciseController::class);
    Route::resource('agent-memories', AgentMemoryController::class)
        ->only(['store', 'update']);
    Route::post(
        'training-plans/{training_plan}/complete',
        CompleteTrainingPlanController::class,
    )->name('training-plans.complete');
    Route::post(
        'training-plans/{training_plan}/note',
        [TrainingNoteController::class, 'store'],
    )->name('training-plans.note.store');
    Route::patch(
        'training-notes/{training_note}',
        [TrainingNoteController::class, 'update'],
    )->name('training-notes.update');
    Route::resource('training-plans', TrainingPlanController::class);
});

require __DIR__.'/settings.php';
