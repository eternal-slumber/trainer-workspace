import { Form, Head, Link } from '@inertiajs/react';
import { Pencil } from 'lucide-react';
import TrainingGroupController from '@/actions/App/Http/Controllers/TrainingGroupController';
import AgentMemorySection from '@/components/agent-memory-section';
import ResourceDeleteDialog from '@/components/resource-delete-dialog';
import TrainingHistoryList from '@/components/training-history-list';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { edit, index } from '@/routes/training-groups';
import type { AgentMemory, TrainingGroup, TrainingHistoryItem } from '@/types';

type TrainingGroupsShowProps = {
    trainingGroup: TrainingGroup;
    trainingHistory: TrainingHistoryItem[];
    agentMemories: AgentMemory[];
};

function Detail({ label, value }: { label: string; value: string | null }) {
    return (
        <div className="grid gap-1">
            <dt className="text-sm text-muted-foreground">{label}</dt>
            <dd className="whitespace-pre-wrap">{value || 'Не указано'}</dd>
        </div>
    );
}

export default function TrainingGroupsShow({
    trainingGroup,
    trainingHistory,
    agentMemories,
}: TrainingGroupsShowProps) {
    return (
        <>
            <Head title={trainingGroup.name} />

            <main className="flex flex-1 flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <h1 className="text-2xl font-semibold tracking-tight">
                            {trainingGroup.name}
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            Карточка тренировочной группы
                        </p>
                    </div>
                    <div className="flex flex-wrap gap-3">
                        <Button variant="outline" asChild>
                            <Link href={edit(trainingGroup)}>
                                <Pencil />
                                Редактировать
                            </Link>
                        </Button>
                        {trainingGroup.archived_at ? (
                            <Form
                                {...TrainingGroupController.restore.form(
                                    trainingGroup,
                                )}
                            >
                                {({ processing }) => (
                                    <Button type="submit" disabled={processing}>
                                        {processing
                                            ? 'Восстановление…'
                                            : 'Восстановить'}
                                    </Button>
                                )}
                            </Form>
                        ) : (
                            <ResourceDeleteDialog
                                form={TrainingGroupController.destroy.form(
                                    trainingGroup,
                                )}
                                archive
                                resourceName={trainingGroup.name}
                            />
                        )}
                    </div>
                </div>

                {trainingGroup.archived_at && (
                    <p
                        role="status"
                        className="rounded-md border bg-muted/30 p-4 text-sm"
                    >
                        Запись в архиве. История сохранена. Для новых тренировок
                        восстановите группу.
                    </p>
                )}

                <Card className="max-w-3xl">
                    <CardContent>
                        <dl className="grid gap-6 sm:grid-cols-2">
                            <Detail
                                label="Вид спорта"
                                value={trainingGroup.sport_type}
                            />
                            <Detail
                                label="Возраст"
                                value={trainingGroup.age_range}
                            />
                            <Detail
                                label="Уровень"
                                value={trainingGroup.level}
                            />
                            <div className="sm:col-span-2">
                                <Detail
                                    label="Цель"
                                    value={trainingGroup.goal}
                                />
                            </div>
                            <div className="sm:col-span-2">
                                <Detail
                                    label="Ограничения"
                                    value={trainingGroup.restrictions}
                                />
                            </div>
                            <div className="sm:col-span-2">
                                <Detail
                                    label="Заметки"
                                    value={trainingGroup.notes}
                                />
                            </div>
                        </dl>
                    </CardContent>
                </Card>

                <AgentMemorySection
                    subject={{
                        id: trainingGroup.id,
                        name: trainingGroup.name,
                        type: 'training_group',
                    }}
                    agentMemories={agentMemories}
                />

                <TrainingHistoryList trainingHistory={trainingHistory} />
            </main>
        </>
    );
}

TrainingGroupsShow.layout = {
    breadcrumbs: [{ title: 'Группы', href: index() }],
};
