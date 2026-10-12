<?php

namespace App\Http\Controllers;

use App\Actions\ScheduledTrainings\EnsureActiveTrainingSubject;
use App\Http\Requests\StoreScheduledTrainingRequest;
use App\Http\Requests\UpdateScheduledTrainingRequest;
use App\Models\ScheduledTraining;
use App\Models\Trainee;
use App\Models\TrainingGroup;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ScheduledTrainingController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', ScheduledTraining::class);

        $scheduledTrainings = $request->user()
            ->scheduledTrainings()
            ->with([
                'trainee:id,name',
                'trainingGroup:id,name',
                'trainingPlan:id,scheduled_training_id,title,status',
            ])
            ->where('starts_at', '>=', Date::now()->startOfDay())
            ->orderBy('starts_at')
            ->get()
            ->map($this->toPageData(...));

        return Inertia::render('scheduled-trainings/index', [
            'scheduledTrainings' => $scheduledTrainings,
        ]);
    }

    public function create(Request $request): Response
    {
        Gate::authorize('create', ScheduledTraining::class);

        return Inertia::render('scheduled-trainings/create', $this->formOptions($request));
    }

    public function store(
        StoreScheduledTrainingRequest $request,
        EnsureActiveTrainingSubject $ensureActiveSubject,
    ): RedirectResponse {
        $scheduledTraining = DB::transaction(function () use ($request, $ensureActiveSubject): ScheduledTraining {
            $attributes = $request->validated();
            $ensureActiveSubject->execute($request->user(), $attributes);

            return $request->user()->scheduledTrainings()->create($attributes);
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Training scheduled.')]);

        return to_route('scheduled-trainings.show', $scheduledTraining);
    }

    public function show(ScheduledTraining $scheduledTraining): Response
    {
        Gate::authorize('view', $scheduledTraining);

        return Inertia::render('scheduled-trainings/show', [
            'scheduledTraining' => $this->toPageData(
                $scheduledTraining->load([
                    'trainee:id,name',
                    'trainingGroup:id,name',
                    'trainingPlan:id,scheduled_training_id,title,status',
                ]),
            ),
        ]);
    }

    public function edit(Request $request, ScheduledTraining $scheduledTraining): Response
    {
        Gate::authorize('update', $scheduledTraining);

        return Inertia::render('scheduled-trainings/edit', [
            'scheduledTraining' => $this->toPageData(
                $scheduledTraining->load(['trainee:id,name', 'trainingGroup:id,name']),
            ),
            ...$this->formOptions($request),
        ]);
    }

    public function update(
        UpdateScheduledTrainingRequest $request,
        ScheduledTraining $scheduledTraining,
        EnsureActiveTrainingSubject $ensureActiveSubject,
    ): RedirectResponse {
        DB::transaction(function () use ($request, $scheduledTraining, $ensureActiveSubject): void {
            $lockedTraining = $request->user()
                ->scheduledTrainings()
                ->lockForUpdate()
                ->findOrFail($scheduledTraining->id);

            $attributes = $request->validatedForUpdate($lockedTraining);
            $trainingPlan = $lockedTraining->trainingPlan()->lockForUpdate()->first();

            if ($trainingPlan !== null
                && ($lockedTraining->status === 'completed') !== ($trainingPlan->status === 'completed')) {
                throw ValidationException::withMessages([
                    'status' => 'Статусы занятия и плана не согласованы. Требуется отдельное исправление данных.',
                ]);
            }

            $status = $attributes['status'] ?? $lockedTraining->status;

            if (($lockedTraining->status === 'completed' && $status !== 'completed')
                || ($trainingPlan !== null && ($status === 'completed') !== ($trainingPlan->status === 'completed'))) {
                throw ValidationException::withMessages([
                    'status' => 'Нельзя изменить статус: завершение занятия с планом выполняется через план, а завершённое занятие нельзя вернуть в расписание или отменить.',
                ]);
            }

            $errors = [];

            foreach (['trainee_id', 'training_group_id'] as $field) {
                if (! array_key_exists($field, $attributes)) {
                    continue;
                }

                $attributes[$field] = $attributes[$field] === null
                    ? null : (int) $attributes[$field];

                if ($attributes[$field] !== $lockedTraining->{$field}) {
                    $errors[$field] = 'Нельзя изменить клиента или группу: для этой тренировки уже создан план.';
                }
            }

            if ($errors !== []) {
                if ($trainingPlan !== null) {
                    throw ValidationException::withMessages($errors);
                }

                $ensureActiveSubject->execute($request->user(), [
                    ...$lockedTraining->only(['trainee_id', 'training_group_id']),
                    ...$attributes,
                ]);
            }

            $lockedTraining->update($attributes);
        });

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Training updated.'),
        ]);

        return to_route('scheduled-trainings.show', $scheduledTraining);
    }

    public function destroy(
        Request $request,
        ScheduledTraining $scheduledTraining,
    ): RedirectResponse {
        Gate::authorize('delete', $scheduledTraining);

        $scheduledTraining->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Training deleted.')]);

        return $request->query('redirect') === 'calendar'
            ? to_route('calendar')
            : to_route('scheduled-trainings.index');
    }

    /**
     * @return array{
     *     id: int,
     *     trainee_id: int|null,
     *     training_group_id: int|null,
     *     starts_at: string,
     *     ends_at: string,
     *     subject_name: string,
     *     subject_type: 'trainee'|'training_group',
     *     location: string,
     *     status: string,
     *     color: string,
     *     notes: string|null,
     *     training_plan: array{id: int, title: string, status: string}|null
     * }
     */
    private function toPageData(ScheduledTraining $scheduledTraining): array
    {
        $isTraineeTraining = $scheduledTraining->trainee_id !== null;

        return [
            'id' => $scheduledTraining->id,
            'trainee_id' => $scheduledTraining->trainee_id,
            'training_group_id' => $scheduledTraining->training_group_id,
            'starts_at' => $scheduledTraining->starts_at->toIso8601String(),
            'ends_at' => $scheduledTraining->ends_at->toIso8601String(),
            'subject_name' => $isTraineeTraining
                ? $scheduledTraining->trainee->name
                : $scheduledTraining->trainingGroup->name,
            'subject_type' => $isTraineeTraining ? 'trainee' : 'training_group',
            'location' => $scheduledTraining->location,
            'status' => $scheduledTraining->status,
            'color' => $scheduledTraining->color,
            'notes' => $scheduledTraining->notes,
            'training_plan' => $scheduledTraining->relationLoaded('trainingPlan')
                && $scheduledTraining->trainingPlan !== null
                ? [
                    'id' => $scheduledTraining->trainingPlan->id,
                    'title' => $scheduledTraining->trainingPlan->title,
                    'status' => $scheduledTraining->trainingPlan->status,
                ]
                : null,
        ];
    }

    /**
     * @return array{
     *     trainees: Collection<int, array{id: int, name: string}>,
     *     trainingGroups: Collection<int, array{id: int, name: string}>
     * }
     */
    private function formOptions(Request $request): array
    {
        return [
            'trainees' => $request->user()
                ->trainees()
                ->where(function ($query) use ($request): void {
                    $query->active();
                    $current = $request->route('scheduled_training');

                    if ($current instanceof ScheduledTraining && $current->trainee_id !== null) {
                        $query->orWhere('id', $current->trainee_id);
                    }
                })
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (Trainee $trainee): array => [
                    'id' => $trainee->id,
                    'name' => $trainee->name,
                ]),
            'trainingGroups' => $request->user()
                ->trainingGroups()
                ->where(function ($query) use ($request): void {
                    $query->active();
                    $current = $request->route('scheduled_training');

                    if ($current instanceof ScheduledTraining && $current->training_group_id !== null) {
                        $query->orWhere('id', $current->training_group_id);
                    }
                })
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (TrainingGroup $trainingGroup): array => [
                    'id' => $trainingGroup->id,
                    'name' => $trainingGroup->name,
                ]),
        ];
    }
}
