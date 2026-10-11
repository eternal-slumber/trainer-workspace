<?php

namespace App\Http\Controllers;

use App\Actions\ScheduledTrainings\EnsureActiveTrainingSubject;
use App\Models\ScheduledTraining;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

class DuplicateScheduledTrainingController extends Controller
{
    public function __invoke(
        Request $request,
        ScheduledTraining $scheduledTraining,
        EnsureActiveTrainingSubject $ensureActiveSubject,
    ): RedirectResponse {
        Gate::authorize('duplicate', $scheduledTraining);

        $attributes = [
            'trainee_id' => $scheduledTraining->trainee_id,
            'training_group_id' => $scheduledTraining->training_group_id,
            'starts_at' => $scheduledTraining->starts_at->addWeek(),
            'ends_at' => $scheduledTraining->ends_at->addWeek(),
            'location' => $scheduledTraining->location,
            'status' => 'planned',
            'color' => $scheduledTraining->color,
            'notes' => null,
        ];

        DB::transaction(function () use ($request, $attributes, $ensureActiveSubject): void {
            $ensureActiveSubject->execute($request->user(), $attributes);
            $request->user()->scheduledTrainings()->create($attributes);
        });

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Training duplicated for next week.'),
        ]);

        return to_route('calendar');
    }
}
