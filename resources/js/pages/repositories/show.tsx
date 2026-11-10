import { Head, Link } from '@inertiajs/react';
import DeleteRepositoryButton from '@/components/delete-repository-button';
import StatusBadge from '@/components/status-badge';
import AppLayout from '@/layouts/app-layout';
import { formatDate, shortSha } from '@/lib/format';
import type { RepositoryItem } from '@/types';

type Props = {
    repository: RepositoryItem;
    /** Adevărat doar pentru utilizatorul autentificat care a adăugat acest repository. */
    can_delete: boolean;
};

export default function RepositoriesShow({ repository, can_delete }: Props) {
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
                        className="mt-1 inline-block rounded-sm text-sm break-all text-indigo-600 hover:text-indigo-500 hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600"
                    >
                        {repository.url} <span aria-hidden="true">↗</span>
                        <span className="sr-only"> (se deschide într-o filă nouă)</span>
                    </a>
                </div>

                {can_delete && <DeleteRepositoryButton repository={repository} />}
            </div>

            <dl className="mt-8 grid gap-6 rounded-lg border border-gray-200 bg-white p-6 shadow-xs sm:grid-cols-2">
                <div>
                    <dt className="text-sm text-gray-500">Ultimul commit</dt>
                    <dd className="mt-1 text-sm text-gray-900">
                        {repository.last_sha ? (
                            <code title={repository.last_sha} className="rounded-sm bg-gray-100 px-1.5 py-0.5 font-mono">
                                {shortSha(repository.last_sha)}
                            </code>
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
