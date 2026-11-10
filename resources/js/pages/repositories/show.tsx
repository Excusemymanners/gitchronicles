import { Form, Head, Link, usePage, usePoll } from '@inertiajs/react';
import { useEffect } from 'react';
import DeleteRepositoryButton from '@/components/delete-repository-button';
import PrimaryButton from '@/components/primary-button';
import StatusBadge from '@/components/status-badge';
import AppLayout from '@/layouts/app-layout';
import { formatDate, shortSha } from '@/lib/format';
import type { RepositoryItem } from '@/types';

type Props = {
    repository: RepositoryItem;
    /** Adevărat doar pentru utilizatorul autentificat care a adăugat acest repository. */
    can_delete: boolean;
    /** Adevărat pentru orice utilizator autentificat, când procesarea a eșuat. */
    can_retry: boolean;
};

const linkClasses =
    'rounded-sm text-indigo-600 hover:text-indigo-500 hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600';

export default function RepositoriesShow({ repository, can_delete, can_retry }: Props) {
    const { auth } = usePage().props;

    // Cât timp procesarea mai are de lucru, cerem serverului la fiecare 2 secunde doar
    // repository-ul și dreptul de „Reîncearcă”; când e gata sau a eșuat, ne oprim.
    const { start, stop } = usePoll(2000, { only: ['repository', 'can_retry'] }, { autoStart: false });

    useEffect(() => {
        if (repository.is_processing) {
            start();
        } else {
            stop();
        }
    }, [repository.is_processing, start, stop]);

    return (
        <AppLayout>
            <Head title={repository.full_name} />

            <Link
                href="/"
                className="rounded-sm text-sm font-medium text-gray-600 hover:text-indigo-600 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600"
            >
                <span aria-hidden="true">←</span> Toate repository-urile
            </Link>

            <div className="mt-4 flex flex-wrap items-start justify-between gap-4">
                <div className="min-w-0">
                    <div className="flex flex-wrap items-center gap-3">
                        <h1 className="text-2xl font-semibold tracking-tight break-words">{repository.full_name}</h1>
                        <StatusBadge status={repository.status} label={repository.status_label} />
                    </div>
                    <a
                        href={repository.url}
                        target="_blank"
                        rel="noopener noreferrer"
                        className={`mt-1 inline-block text-sm break-all ${linkClasses}`}
                    >
                        {repository.url} <span aria-hidden="true">↗</span>
                        <span className="sr-only"> (se deschide într-o filă nouă)</span>
                    </a>
                </div>

                {can_delete && <DeleteRepositoryButton repository={repository} />}
            </div>

            {repository.is_processing && (
                <p
                    role="status"
                    className="mt-6 flex items-center gap-2 rounded-lg border border-sky-200 bg-sky-50 px-4 py-3 text-sm text-sky-900"
                >
                    <span aria-hidden="true" className="size-2 shrink-0 rounded-full bg-sky-500 motion-safe:animate-pulse" />
                    Încă se procesează… Pagina se actualizează singură.
                </p>
            )}

            {repository.status === 'failed' && (
                <div
                    role="alert"
                    className="mt-6 flex flex-col gap-3 rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-900 sm:flex-row sm:items-center sm:justify-between"
                >
                    <p>
                        <span className="font-semibold">Procesarea a eșuat.</span> {repository.error}
                    </p>

                    {/* Oricine are cont poate reporni procesarea; un vizitator e invitat să intre în cont. */}
                    {can_retry ? (
                        <Form action={`/repositories/${repository.id}/retry`} method="post" className="shrink-0">
                            {({ processing }) => (
                                <PrimaryButton disabled={processing}>{processing ? 'Se pornește…' : 'Reîncearcă'}</PrimaryButton>
                            )}
                        </Form>
                    ) : (
                        !auth.user && (
                            <p className="shrink-0">
                                <Link href="/login" className={`font-medium ${linkClasses}`}>
                                    Intră în cont
                                </Link>{' '}
                                ca să poți reîncerca.
                            </p>
                        )
                    )}
                </div>
            )}

            <dl className="mt-8 grid gap-6 rounded-lg border border-gray-200 bg-white p-6 shadow-xs sm:grid-cols-2">
                <div>
                    <dt className="text-sm text-gray-500">Ultimul commit procesat</dt>
                    <dd className="mt-1 text-sm text-gray-900">
                        {repository.last_sha ? (
                            <a
                                href={`https://github.com/${repository.owner}/${repository.name}/commit/${repository.last_sha}`}
                                target="_blank"
                                rel="noopener noreferrer"
                                title={repository.last_sha}
                                className={linkClasses}
                            >
                                <code className="rounded-sm bg-gray-100 px-1.5 py-0.5 font-mono">{shortSha(repository.last_sha)}</code>
                                <span className="sr-only"> (commitul pe GitHub, se deschide într-o filă nouă)</span>
                            </a>
                        ) : (
                            <span className="text-gray-500">încă neclonat</span>
                        )}
                    </dd>
                </div>
                <div>
                    <dt className="text-sm text-gray-500">Ramura principală</dt>
                    <dd className="mt-1 text-sm text-gray-900">
                        {repository.default_branch ? (
                            <code className="rounded-sm bg-gray-100 px-1.5 py-0.5 font-mono">{repository.default_branch}</code>
                        ) : (
                            <span className="text-gray-500">încă necunoscută</span>
                        )}
                    </dd>
                </div>
                <div>
                    <dt className="text-sm text-gray-500">Clonat la</dt>
                    <dd className="mt-1 text-sm text-gray-900">
                        {repository.cloned_at ? (
                            <time dateTime={repository.cloned_at}>{formatDate(repository.cloned_at)}</time>
                        ) : (
                            <span className="text-gray-500">încă neclonat</span>
                        )}
                    </dd>
                </div>
                <div>
                    <dt className="text-sm text-gray-500">Adăugat la</dt>
                    <dd className="mt-1 text-sm text-gray-900">
                        <time dateTime={repository.created_at}>{formatDate(repository.created_at)}</time>
                    </dd>
                </div>
            </dl>
        </AppLayout>
    );
}
