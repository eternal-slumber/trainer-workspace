import { Form } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import type { RouteFormDefinition } from '@/wayfinder';

type ResourceDeleteDialogProps = {
    form: RouteFormDefinition<'post'>;
    resourceName: string;
    archive?: boolean;
};

export default function ResourceDeleteDialog({
    form,
    resourceName,
    archive = false,
}: ResourceDeleteDialogProps) {
    const actionLabel = archive ? 'Архивировать' : 'Удалить';

    return (
        <Dialog>
            <DialogTrigger asChild>
                <Button variant={archive ? 'outline' : 'destructive'}>
                    {actionLabel}
                </Button>
            </DialogTrigger>

            <DialogContent>
                <DialogHeader>
                    <DialogTitle>
                        {actionLabel} «{resourceName}»?
                    </DialogTitle>
                    <DialogDescription>
                        {archive
                            ? 'История, планы и заметки сохранятся. Запись можно восстановить из архива.'
                            : 'Запись будет удалена без возможности восстановления.'}
                    </DialogDescription>
                </DialogHeader>

                <DialogFooter>
                    <DialogClose asChild>
                        <Button variant="outline">Отмена</Button>
                    </DialogClose>

                    <Form {...form}>
                        {({ processing }) => (
                            <Button
                                type="submit"
                                variant={archive ? 'outline' : 'destructive'}
                                disabled={processing}
                            >
                                {processing
                                    ? archive
                                        ? 'Архивирование…'
                                        : 'Удаление…'
                                    : actionLabel}
                            </Button>
                        )}
                    </Form>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
