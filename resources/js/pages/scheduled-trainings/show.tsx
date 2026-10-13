import { Form, Head, Link, router, usePoll } from '@inertiajs/react';
import { ClipboardList, LoaderCircle, Pencil, Sparkles } from 'lucide-react';
import { useEffect, useRef } from 'react';
import ReserveTrainingPlanGenerationController from '@/actions/App/Http/Controllers/ReserveTrainingPlanGenerationController';
import ScheduledTrainingController from '@/actions/App/Http/Controllers/ScheduledTrainingController';
import TrainingPlanGenerationController from '@/actions/App/Http/Controllers/TrainingPlanGenerationController';
import InputError from '@/components/input-error';
import ResourceDeleteDialog from '@/components/resource-delete-dialog';
import ScheduledTrainingColorDot from '@/components/scheduled-training-color-dot';
import ScheduledTrainingStatusBadge from '@/components/scheduled-training-status-badge';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { getScheduledTrainingColor } from '@/lib/scheduled-training-colors';
import { edit, index } from '@/routes/scheduled-trainings';
import {
    create as createTrainingPlan,
    show as showTrainingPlan,
} from '@/routes/training-plans';
import type { ScheduledTraining } from '@/types';

type ScheduledTrainingsShowProps = {
    scheduledTraining: ScheduledTraining;
    generation: {
        id: string;
        status:
            | 'queued'
            | 'running'
            | 'uncertain'
            | 'succeeded'
            | 'failed'
            | 'stale'
            | 'cancelled';
        training_plan_id: number | null;
        error_code: string | null;
        cancel_requested_at: string | null;
    } | null;
    generationRequestKey: string;
    canGenerateAI: boolean;
};

const dateTimeFormatter = new Intl.DateTimeFormat('ru-RU', {
    dateStyle: 'long',
    timeStyle: 'short',
});

export default function ScheduledTrainingsShow({
    scheduledTraining,
    generation,
    generationRequestKey,
    canGenerateAI,
}: ScheduledTrainingsShowProps) {
    const colorOption = getScheduledTrainingColor(scheduledTraining.color);
    const pending =
        generation?.status === 'queued' || generation?.status === 'running';
    const occupied = pending || generation?.status === 'uncertain';
    const watching = useRef(pending);
    const { start, stop } = usePoll(
        2000,
        {
            only: [
                'scheduledTraining',
                'generation',
                'generationRequestKey',
                'canGenerateAI',
            ],
        },
        { autoStart: false },
    );
    useEffect(() => {
        if (pending) {
            watching.current = true;
            start();
        } else {
            stop();
        }
    }, [pending, start, stop]);
    useEffect(() => {
        if (
            generation?.status === 'succeeded' &&
            generation.training_plan_id &&
            watching.current
        ) {
            watching.current = false;
            router.visit(showTrainingPlan(generation.training_plan_id));
        }
    }, [generation]);
    const labels = {
        queued: 'Ожидает обработки',
        running: 'Создаём AI-план',
        uncertain: 'Результат запроса неизвестен',
        succeeded: 'AI-план готов',
        failed: 'Не удалось создать план',
        stale: 'Данные изменились — запустите генерацию заново',
        cancelled: 'Генерация отменена',
    };

    return (
        <>
            <Head title={scheduledTraining.subject_name} />

            <main className="flex flex-1 flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <div className="grid gap-1">
                        <h1 className="text-2xl font-semibold tracking-tight">
                            {scheduledTraining.subject_name}
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            {scheduledTraining.subject_type === 'trainee'
                                ? 'Индивидуальная тренировка'
                                : 'Групповая тренировка'}
                        </p>
                    </div>
                    <div className="flex flex-wrap gap-3">
                        <Button variant="outline" asChild>
                            <Link href={edit(scheduledTraining)}>
                                <Pencil />
                                Редактировать
                            </Link>
                        </Button>
                        <ResourceDeleteDialog
                            form={ScheduledTrainingController.destroy.form(
                                scheduledTraining,
                            )}
                            resourceName={scheduledTraining.subject_name}
                        />
                    </div>
                </div>

                <Card className="max-w-3xl">
                    <CardContent>
                        <dl className="grid gap-6 sm:grid-cols-2">
                            <div className="grid gap-1 sm:col-span-2">
                                <dt className="text-sm text-muted-foreground">
                                    Время
                                </dt>
                                <dd>
                                    {dateTimeFormatter.format(
                                        new Date(scheduledTraining.starts_at),
                                    )}{' '}
                                    —{' '}
                                    {dateTimeFormatter.format(
                                        new Date(scheduledTraining.ends_at),
                                    )}
                                </dd>
                            </div>
                            <div className="grid gap-1">
                                <dt className="text-sm text-muted-foreground">
                                    Локация
                                </dt>
                                <dd>{scheduledTraining.location}</dd>
                            </div>
                            <div className="grid gap-1">
                                <dt className="text-sm text-muted-foreground">
                                    Цвет в календаре
                                </dt>
                                <dd className="flex items-center gap-2">
                                    <ScheduledTrainingColorDot
                                        color={scheduledTraining.color}
                                    />
                                    {colorOption.label}
                                </dd>
                            </div>
                            <div className="grid gap-1">
                                <dt className="text-sm text-muted-foreground">
                                    Статус
                                </dt>
                                <dd>
                                    <ScheduledTrainingStatusBadge
                                        status={scheduledTraining.status}
                                    />
                                </dd>
                            </div>
                            <div className="grid gap-1 sm:col-span-2">
                                <dt className="text-sm text-muted-foreground">
                                    Заметка
                                </dt>
                                <dd className="whitespace-pre-wrap">
                                    {scheduledTraining.notes || 'Не указано'}
                                </dd>
                            </div>
                        </dl>
                    </CardContent>
                </Card>

                {generation && (
                    <Alert className="max-w-3xl">
                        <AlertTitle>
                            {generation.cancel_requested_at && pending
                                ? 'Ожидаем завершения отменённого запроса'
                                : labels[generation.status]}
                        </AlertTitle>
                        <AlertDescription>
                            {generation.status === 'uncertain' && (
                                <p>
                                    Провайдер мог обработать запрос.
                                    Автоматического повтора не будет. Новый
                                    запуск может привести к дополнительным
                                    расходам.
                                </p>
                            )}
                            {generation.status === 'failed' && (
                                <p>
                                    {generation.error_code ===
                                        'configuration_error' ||
                                    generation.error_code ===
                                        'provider_rejected'
                                        ? 'Проверьте настройки AI-провайдера или попробуйте позже.'
                                        : 'Результат не сохранён. Можно запустить новую генерацию.'}
                                </p>
                            )}
                            {occupied &&
                                (!generation.cancel_requested_at ||
                                    generation.status === 'uncertain') && (
                                    <Form
                                        {...TrainingPlanGenerationController.cancel.form(
                                            generation,
                                        )}
                                    >
                                        {({ processing, errors }) => (
                                            <>
                                                {generation.status ===
                                                    'uncertain' && (
                                                    <input
                                                        type="hidden"
                                                        name="acknowledge_unknown"
                                                        value="1"
                                                    />
                                                )}
                                                <Button
                                                    variant="outline"
                                                    type="submit"
                                                    disabled={processing}
                                                >
                                                    {generation.status ===
                                                    'uncertain'
                                                        ? 'Закрыть операцию с неизвестным результатом'
                                                        : 'Отменить генерацию'}
                                                </Button>
                                                <InputError
                                                    message={
                                                        errors.acknowledge_unknown
                                                    }
                                                />
                                            </>
                                        )}
                                    </Form>
                                )}
                        </AlertDescription>
                    </Alert>
                )}

                <Card className="max-w-3xl">
                    <CardContent className="flex flex-wrap items-center justify-between gap-4">
                        <div className="grid gap-1">
                            <h2 className="font-semibold">План тренировки</h2>
                            <p className="text-sm text-muted-foreground">
                                {scheduledTraining.training_plan
                                    ? `${scheduledTraining.training_plan.title} · ${scheduledTraining.training_plan.status}`
                                    : 'План для этой тренировки ещё не создан'}
                            </p>
                        </div>
                        {scheduledTraining.training_plan ? (
                            <Button asChild>
                                <Link
                                    href={showTrainingPlan(
                                        scheduledTraining.training_plan,
                                    )}
                                >
                                    <ClipboardList />
                                    Открыть план
                                </Link>
                            </Button>
                        ) : scheduledTraining.status === 'completed' ? (
                            <p className="text-sm text-muted-foreground">
                                К завершённому занятию нельзя добавить новый
                                план.
                            </p>
                        ) : (
                            <div className="flex flex-wrap gap-3">
                                <Button variant="outline" asChild>
                                    <Link
                                        href={createTrainingPlan({
                                            query: {
                                                scheduled_training:
                                                    scheduledTraining.id,
                                            },
                                        })}
                                    >
                                        <ClipboardList />
                                        Создать вручную
                                    </Link>
                                </Button>
                                <Form
                                    {...ReserveTrainingPlanGenerationController.form(
                                        scheduledTraining,
                                    )}
                                    disableWhileProcessing
                                    onBefore={() => {
                                        watching.current = true;
                                    }}
                                >
                                    {({ processing, errors }) => (
                                        <>
                                            <input
                                                type="hidden"
                                                name="request_key"
                                                value={generationRequestKey}
                                            />
                                            <Button
                                                type="submit"
                                                disabled={
                                                    processing ||
                                                    occupied ||
                                                    !canGenerateAI
                                                }
                                                aria-busy={processing}
                                            >
                                                {processing ? (
                                                    <LoaderCircle className="animate-spin" />
                                                ) : (
                                                    <Sparkles />
                                                )}
                                                {processing
                                                    ? 'Генерация…'
                                                    : 'Сгенерировать AI-план'}
                                            </Button>
                                            <InputError
                                                message={errors.request_key}
                                            />
                                            <InputError
                                                message={
                                                    errors.scheduled_training_id
                                                }
                                            />
                                        </>
                                    )}
                                </Form>
                            </div>
                        )}
                    </CardContent>
                </Card>
            </main>
        </>
    );
}

ScheduledTrainingsShow.layout = {
    breadcrumbs: [{ title: 'Расписание', href: index() }],
};
