import { Form, Head, Link } from '@inertiajs/react';
import {
    AlertTriangle,
    CalendarDays,
    CheckCircle2,
    Pencil,
    Sparkles,
} from 'lucide-react';
import TrainingPlanController from '@/actions/App/Http/Controllers/TrainingPlanController';
import InputError from '@/components/input-error';
import ResourceDeleteDialog from '@/components/resource-delete-dialog';
import TrainingNoteSection from '@/components/training-note-section';
import TrainingPlanStatusBadge from '@/components/training-plan-status-badge';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { show as showScheduledTraining } from '@/routes/scheduled-trainings';
import { complete, edit, index } from '@/routes/training-plans';
import type { TrainingPlan, TrainingPlanExercise } from '@/types';

type TrainingPlansShowProps = {
    trainingPlan: TrainingPlan;
};

const dateFormatter = new Intl.DateTimeFormat('ru-RU', {
    dateStyle: 'long',
    timeStyle: 'short',
});

function exerciseVolume(exercise: TrainingPlanExercise): string | null {
    const parts = [
        exercise.duration_minutes ? `${exercise.duration_minutes} мин` : null,
        exercise.sets ? formatSets(exercise.sets) : null,
        exercise.repetitions ? formatRepetitions(exercise.repetitions) : null,
        exercise.rest_seconds !== null
            ? `отдых ${exercise.rest_seconds} сек`
            : null,
    ].filter(Boolean);

    return parts.length > 0 ? parts.join(' · ') : null;
}

function formatSets(sets: number): string {
    const lastTwoDigits = sets % 100;
    const lastDigit = sets % 10;
    let label = 'подходов';

    if (lastTwoDigits < 11 || lastTwoDigits > 14) {
        if (lastDigit === 1) {
            label = 'подход';
        } else if (lastDigit >= 2 && lastDigit <= 4) {
            label = 'подхода';
        }
    }

    return `${sets} ${label}`;
}

function formatRepetitions(repetitions: string): string {
    return /^\d+$/.test(repetitions)
        ? `${repetitions} повторений`
        : repetitions;
}

export default function TrainingPlansShow({
    trainingPlan,
}: TrainingPlansShowProps) {
    return (
        <>
            <Head title={trainingPlan.title} />

            <main className="flex flex-1 flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <div className="grid gap-2">
                        <div className="flex flex-wrap items-center gap-2">
                            <h1 className="text-2xl font-semibold tracking-tight">
                                {trainingPlan.title}
                            </h1>
                            <TrainingPlanStatusBadge
                                status={trainingPlan.status}
                            />
                            <Badge variant="outline">
                                {trainingPlan.source === 'manual'
                                    ? 'Создан вручную'
                                    : 'Создан AI'}
                            </Badge>
                        </div>
                        <p className="text-sm text-muted-foreground">
                            {trainingPlan.subject_name} ·{' '}
                            {trainingPlan.total_duration_minutes} мин.
                        </p>
                    </div>

                    <div className="flex flex-wrap gap-3">
                        {['draft', 'approved'].includes(trainingPlan.status) &&
                            trainingPlan.scheduled_training.status ===
                                'planned' && (
                                <Form
                                    {...complete.form(trainingPlan)}
                                    className="grid gap-1"
                                >
                                    {({ processing, errors }) => (
                                        <>
                                            <Button
                                                type="submit"
                                                disabled={processing}
                                            >
                                                <CheckCircle2 />
                                                {processing
                                                    ? 'Завершение…'
                                                    : 'Отметить как проведённую'}
                                            </Button>
                                            <InputError
                                                message={errors.status}
                                            />
                                        </>
                                    )}
                                </Form>
                            )}
                        {trainingPlan.status !== 'completed' &&
                            trainingPlan.scheduled_training.status !==
                                'completed' && (
                                <Button variant="outline" asChild>
                                    <Link href={edit(trainingPlan)}>
                                        <Pencil />
                                        Редактировать
                                    </Link>
                                </Button>
                            )}
                        <ResourceDeleteDialog
                            form={TrainingPlanController.destroy.form(
                                trainingPlan,
                            )}
                            resourceName={trainingPlan.title}
                        />
                    </div>
                </div>

                {(trainingPlan.status === 'completed') !==
                    (trainingPlan.scheduled_training.status ===
                        'completed') && (
                    <p role="alert" className="text-sm text-destructive">
                        Статусы занятия и плана не согласованы. Требуется
                        отдельное исправление данных.
                    </p>
                )}

                {trainingPlan.status === 'completed' && (
                    <p className="text-sm text-muted-foreground">
                        Завершённый план доступен только для просмотра. Заметки
                        о тренировке редактируются отдельно.
                    </p>
                )}

                <Card className="max-w-5xl">
                    <CardContent className="grid gap-6 sm:grid-cols-2">
                        <div className="grid gap-1 sm:col-span-2">
                            <p className="text-sm text-muted-foreground">
                                Запланированная тренировка
                            </p>
                            <Link
                                href={showScheduledTraining(
                                    trainingPlan.scheduled_training,
                                )}
                                className="flex w-fit items-center gap-2 font-medium hover:underline"
                            >
                                <CalendarDays className="size-4" />
                                {dateFormatter.format(
                                    new Date(
                                        trainingPlan.scheduled_training
                                            .starts_at,
                                    ),
                                )}{' '}
                                · {trainingPlan.scheduled_training.location}
                            </Link>
                        </div>
                        <div className="grid gap-1 sm:col-span-2">
                            <p className="text-sm text-muted-foreground">
                                Цель
                            </p>
                            <p className="whitespace-pre-wrap">
                                {trainingPlan.goal}
                            </p>
                        </div>
                        <div className="grid gap-1 sm:col-span-2">
                            <p className="text-sm text-muted-foreground">
                                Заметки
                            </p>
                            <p className="whitespace-pre-wrap">
                                {trainingPlan.notes || 'Не указано'}
                            </p>
                        </div>
                    </CardContent>
                </Card>

                {trainingPlan.source === 'ai' && (
                    <Card className="max-w-5xl border-violet-200 bg-violet-50/60 dark:border-violet-900 dark:bg-violet-950/20">
                        <CardHeader className="gap-2">
                            <Badge
                                variant="outline"
                                className="w-fit border-violet-300 text-violet-700 dark:border-violet-800 dark:text-violet-300"
                            >
                                <Sparkles />
                                Сгенерировано AI
                            </Badge>
                            <CardTitle>AI-пояснение</CardTitle>
                        </CardHeader>
                        <CardContent className="grid gap-5">
                            <p className="whitespace-pre-wrap">
                                {trainingPlan.ai_reasoning ||
                                    'AI не предоставил пояснение для этого плана.'}
                            </p>

                            {trainingPlan.warnings.length > 0 && (
                                <section className="grid gap-2">
                                    <h3 className="flex items-center gap-2 font-medium text-amber-800 dark:text-amber-300">
                                        <AlertTriangle className="size-4" />
                                        Предупреждения
                                    </h3>
                                    <ul className="grid list-disc gap-1 pl-5 text-sm">
                                        {trainingPlan.warnings.map(
                                            (warning, index) => (
                                                <li key={`${index}-${warning}`}>
                                                    {warning}
                                                </li>
                                            ),
                                        )}
                                    </ul>
                                </section>
                            )}
                        </CardContent>
                    </Card>
                )}

                <TrainingNoteSection trainingPlan={trainingPlan} />

                <section className="grid max-w-5xl gap-4" aria-label="План">
                    {trainingPlan.blocks.map((block) => (
                        <Card key={block.id ?? block.position}>
                            <CardHeader className="flex-row items-start justify-between gap-4">
                                <div className="grid gap-1">
                                    <CardTitle>
                                        {block.position}. {block.name}
                                    </CardTitle>
                                    {block.notes && (
                                        <p className="text-sm text-muted-foreground">
                                            {block.notes}
                                        </p>
                                    )}
                                </div>
                                <Badge variant="secondary">
                                    {block.duration_minutes} мин.
                                </Badge>
                            </CardHeader>
                            <CardContent className="grid gap-3">
                                {block.exercises.length === 0 ? (
                                    <p className="text-sm text-muted-foreground">
                                        Упражнения не добавлены
                                    </p>
                                ) : (
                                    block.exercises.map((exercise) => (
                                        <article
                                            key={
                                                exercise.id ?? exercise.position
                                            }
                                            className="grid gap-2 rounded-lg border p-4"
                                        >
                                            <div className="flex flex-wrap items-start justify-between gap-3">
                                                <div>
                                                    <h3 className="font-medium">
                                                        {exercise.position}.{' '}
                                                        {exercise.name}
                                                    </h3>
                                                    {exercise.description && (
                                                        <p className="mt-1 text-sm whitespace-pre-wrap text-muted-foreground">
                                                            {
                                                                exercise.description
                                                            }
                                                        </p>
                                                    )}
                                                </div>
                                                <Badge variant="outline">
                                                    {exercise.exercise_id
                                                        ? 'Из базы'
                                                        : 'Вручную'}
                                                </Badge>
                                            </div>
                                            {exerciseVolume(exercise) && (
                                                <p className="text-sm font-medium">
                                                    {exerciseVolume(exercise)}
                                                </p>
                                            )}
                                            {exercise.notes && (
                                                <p className="text-sm text-muted-foreground">
                                                    Заметка: {exercise.notes}
                                                </p>
                                            )}
                                        </article>
                                    ))
                                )}
                            </CardContent>
                        </Card>
                    ))}
                </section>
            </main>
        </>
    );
}

TrainingPlansShow.layout = {
    breadcrumbs: [{ title: 'Планы тренировок', href: index() }],
};
