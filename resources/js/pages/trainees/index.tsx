import { Head, Link } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { create, index, show } from '@/routes/trainees';
import type { TraineeListItem } from '@/types';

type TraineesIndexProps = {
    archived: boolean;
    trainees: TraineeListItem[];
};

export default function TraineesIndex({
    trainees,
    archived,
}: TraineesIndexProps) {
    return (
        <>
            <Head title={archived ? 'Архив клиентов' : 'Клиенты'} />

            <main className="flex flex-1 flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <Heading
                        title={archived ? 'Архив клиентов' : 'Клиенты'}
                        description="Индивидуальные клиенты и их тренировочные цели"
                    />
                    <Button asChild>
                        <Link href={create()}>
                            <Plus />
                            Добавить клиента
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

                {trainees.length === 0 ? (
                    <Card>
                        <CardContent className="py-10 text-center text-sm text-muted-foreground">
                            {archived
                                ? 'Архив клиентов пуст'
                                : 'Клиентов пока нет'}
                        </CardContent>
                    </Card>
                ) : (
                    <section
                        className="grid gap-3 md:grid-cols-2 xl:grid-cols-3"
                        aria-label="Список клиентов"
                    >
                        {trainees.map((trainee) => (
                            <Card key={trainee.id} className="gap-3">
                                <CardContent className="grid gap-3">
                                    <div>
                                        <Link
                                            href={show(trainee)}
                                            className="font-semibold hover:underline"
                                        >
                                            {trainee.name}
                                        </Link>
                                        <p className="text-sm text-muted-foreground">
                                            {trainee.age
                                                ? `${trainee.age} лет · `
                                                : ''}
                                            {trainee.level}
                                        </p>
                                    </div>
                                    <p className="line-clamp-2 text-sm">
                                        {trainee.goal}
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

TraineesIndex.layout = {
    breadcrumbs: [{ title: 'Клиенты', href: index() }],
};
