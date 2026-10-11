import { Head, Link } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { create, index, show } from '@/routes/training-groups';
import type { TrainingGroupListItem } from '@/types';

type TrainingGroupsIndexProps = {
    archived: boolean;
    trainingGroups: TrainingGroupListItem[];
};

export default function TrainingGroupsIndex({
    trainingGroups,
    archived,
}: TrainingGroupsIndexProps) {
    return (
        <>
            <Head title={archived ? 'Архив групп' : 'Группы'} />

            <main className="flex flex-1 flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <Heading
                        title={archived ? 'Архив групп' : 'Группы'}
                        description="Тренировочные группы и их общие цели"
                    />
                    <Button asChild>
                        <Link href={create()}>
                            <Plus />
                            Добавить группу
                        </Link>
                    </Button>
                </div>

                <div className="flex gap-2">
                    <Button
                        variant={archived ? 'outline' : 'secondary'}
                        asChild
                    >
                        <Link href={index()}>Активные</Link>
                    </Button>
                    <Button
                        variant={archived ? 'secondary' : 'outline'}
                        asChild
                    >
                        <Link href={index({ query: { archived: 1 } })}>
                            Архив
                        </Link>
                    </Button>
                </div>

                {trainingGroups.length === 0 ? (
                    <Card>
                        <CardContent className="py-10 text-center text-sm text-muted-foreground">
                            {archived ? 'Архив групп пуст' : 'Групп пока нет'}
                        </CardContent>
                    </Card>
                ) : (
                    <section
                        className="grid gap-3 md:grid-cols-2 xl:grid-cols-3"
                        aria-label="Список групп"
                    >
                        {trainingGroups.map((trainingGroup) => (
                            <Card key={trainingGroup.id} className="gap-3">
                                <CardContent className="grid gap-3">
                                    <div>
                                        <Link
                                            href={show(trainingGroup)}
                                            className="font-semibold hover:underline"
                                        >
                                            {trainingGroup.name}
                                        </Link>
                                        <p className="text-sm text-muted-foreground">
                                            {trainingGroup.sport_type} ·{' '}
                                            {trainingGroup.age_range}
                                        </p>
                                    </div>
                                    <p className="text-sm text-muted-foreground">
                                        Уровень: {trainingGroup.level}
                                    </p>
                                    <p className="line-clamp-2 text-sm">
                                        {trainingGroup.goal}
                                    </p>
                                </CardContent>
                            </Card>
                        ))}
                    </section>
                )}
            </main>
        </>
    );
}

TrainingGroupsIndex.layout = {
    breadcrumbs: [{ title: 'Группы', href: index() }],
};
