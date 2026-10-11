<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreTrainingGroupRequest;
use App\Http\Requests\UpdateTrainingGroupRequest;
use App\Models\TrainingGroup;
use App\Models\TrainingPlan;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class TrainingGroupController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', TrainingGroup::class);

        $archived = $request->boolean('archived');

        return Inertia::render('training-groups/index', [
            'trainingGroups' => $request->user()
                ->trainingGroups()
                ->when($archived, fn ($query) => $query->whereNotNull('archived_at'), fn ($query) => $query->active())
                ->select(['id', 'name', 'sport_type', 'age_range', 'level', 'goal'])
                ->latest()
                ->get(),
            'archived' => $archived,
        ]);
    }

    public function create(): Response
    {
        Gate::authorize('create', TrainingGroup::class);

        return Inertia::render('training-groups/create');
    }

    public function store(StoreTrainingGroupRequest $request): RedirectResponse
    {
        $trainingGroup = $request->user()->trainingGroups()->create($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Training group created.')]);

        return to_route('training-groups.show', $trainingGroup);
    }

    public function show(TrainingGroup $trainingGroup): Response
    {
        Gate::authorize('view', $trainingGroup);

        return Inertia::render('training-groups/show', [
            'trainingGroup' => $trainingGroup->only([
                'id',
                'name',
                'sport_type',
                'age_range',
                'level',
                'goal',
                'restrictions',
                'notes',
                'archived_at',
            ]),
            'trainingHistory' => $this->trainingHistory($trainingGroup),
            'agentMemories' => $trainingGroup->agentMemories()
                ->where('user_id', $trainingGroup->user_id)
                ->select(['id', 'type', 'content', 'importance', 'is_active'])
                ->orderByDesc('is_active')
                ->orderByDesc('importance')
                ->latest()
                ->get(),
        ]);
    }

    public function edit(TrainingGroup $trainingGroup): Response
    {
        Gate::authorize('update', $trainingGroup);

        return Inertia::render('training-groups/edit', [
            'trainingGroup' => $trainingGroup->only([
                'id',
                'name',
                'sport_type',
                'age_range',
                'level',
                'goal',
                'restrictions',
                'notes',
                'archived_at',
            ]),
        ]);
    }

    public function update(UpdateTrainingGroupRequest $request, TrainingGroup $trainingGroup): RedirectResponse
    {
        $trainingGroup->update($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Training group updated.')]);

        return to_route('training-groups.show', $trainingGroup);
    }

    public function destroy(TrainingGroup $trainingGroup): RedirectResponse
    {
        Gate::authorize('delete', $trainingGroup);

        $trainingGroup->archive();

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Группа перемещена в архив.']);

        return to_route('training-groups.index');
    }

    public function restore(TrainingGroup $trainingGroup): RedirectResponse
    {
        Gate::authorize('restore', $trainingGroup);

        $trainingGroup->restore();

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Группа восстановлена.']);

        return to_route('training-groups.show', $trainingGroup);
    }

    /** @return Collection<int, covariant array<string, mixed>> */
    private function trainingHistory(TrainingGroup $trainingGroup): Collection
    {
        return $trainingGroup->trainingPlans()
            ->completed()
            ->latestScheduled()
            ->with([
                'scheduledTraining:id,starts_at',
                'trainingNote:id,training_plan_id,intensity,result,tags,note',
            ])
            ->limit(10)
            ->get([
                'id',
                'scheduled_training_id',
                'title',
                'goal',
                'total_duration_minutes',
                'status',
            ])
            ->map(fn (TrainingPlan $trainingPlan): array => [
                'id' => $trainingPlan->id,
                'title' => $trainingPlan->title,
                'goal' => $trainingPlan->goal,
                'total_duration_minutes' => $trainingPlan->total_duration_minutes,
                'status' => $trainingPlan->status,
                'starts_at' => $trainingPlan->scheduledTraining->starts_at->toIso8601String(),
                'training_note' => $trainingPlan->trainingNote?->only([
                    'intensity',
                    'result',
                    'tags',
                    'note',
                ]),
            ]);
    }
}
