import { Form, Link, router, usePage, usePoll } from '@inertiajs/react';
import { useEffect, useRef } from 'react';
import type { ReactNode } from 'react';
import DeleteRepositoryButton from '@/components/delete-repository-button';
import PrimaryButton from '@/components/primary-button';
import StatusBadge from '@/components/status-badge';
import AppLayout from '@/layouts/app-layout';
import { shortSha } from '@/lib/format';
import type { RepositoryPageProps } from '@/types';

type Props = {
    children: ReactNode;
};

type Tab = {
    label: string;
    /** Pagina Inertia a tab-ului: după ea știm care tab e deschis. */
    component: string;
    /** Ce se adaugă după /repositories/{id}. */
    path: string;
};

/** Tab-urile unui repository, în ordinea din bară. Fiecare are ruta și pagina lui. */
const tabs: Tab[] = [
    { label: 'Prezentare', component: 'repositories/show', path: '' },
    { label: 'Documentație', component: 'repositories/docs', path: '/docs' },
    { label: 'Diagrame', component: 'repositories/diagrams', path: '/diagrams' },
    { label: 'Asistent', component: 'repositories/assistant', path: '/assistant' },
    { label: 'Istoric', component: 'repositories/timeline', path: '/timeline' },
    { label: 'Video', component: 'repositories/media', path: '/media' },
];

const focusRing = 'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600';

const linkClasses = `rounded-sm text-indigo-600 hover:text-indigo-500 hover:underline ${focusRing}`;

/**
 * Rama comună a paginilor unui repository: antetul (nume, status, GitHub, ramura și commitul,
 * „Reîncearcă”, „Șterge”) și tab-urile. Prop-urile antetului vin pe fiecare pagină de la server
 * (RendersRepositoryPage), așa că layout-ul le citește singur cu usePage().
 */
export default function RepositoryLayout({ children }: Props) {
    const { props, component } = usePage<RepositoryPageProps>();
    const { auth, repository, can_delete, can_retry } = props;

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

    // Când se schimbă etapa (de exemplu s-a terminat analiza), cerem din nou toată pagina:
    // o etapă terminată poate aduce date noi pentru secțiunile paginii.
    const shownStatus = useRef(repository.status);

    useEffect(() => {
        if (shownStatus.current !== repository.status) {
            shownStatus.current = repository.status;
            router.reload();
        }
    }, [repository.status]);

    // Pe telefon bara de tab-uri se derulează orizontal: aducem în vedere tab-ul deschis.
    // Așteptăm fonturile, pentru că ele schimbă lățimea tab-urilor.
    const tabListRef = useRef<HTMLUListElement>(null);
    const activeTabRef = useRef<HTMLLIElement>(null);

    useEffect(() => {
        document.fonts.ready.then(() => {
            const tabList = tabListRef.current;

            if (tabList && tabList.scrollWidth > tabList.clientWidth) {
                activeTabRef.current?.scrollIntoView({ block: 'nearest', inline: 'nearest' });
            }
        });
    }, []);

    return (
        <AppLayout>
            <Link
                href="/"
                className={`rounded-sm text-sm font-medium text-gray-600 hover:text-indigo-600 ${focusRing}`}
            >
                <span aria-hidden="true">←</span> Toate repository-urile
            </Link>

            <div className="mt-4 flex flex-wrap items-start justify-between gap-4">
                <div className="min-w-0">
                    <div className="flex flex-wrap items-center gap-3">
                        <h1 className="text-2xl font-semibold tracking-tight break-words">{repository.full_name}</h1>
                        <StatusBadge status={repository.status} label={repository.status_label} />
                    </div>

                    {repository.description && <p className="mt-1 text-gray-600">{repository.description}</p>}

                    {/* Ramura și commitul apar doar după prima clonare: până atunci nu le știm. */}
                    <div className="mt-2 flex flex-wrap items-center gap-x-4 gap-y-1 text-sm text-gray-600">
                        <a href={repository.url} target="_blank" rel="noopener noreferrer" className={`break-all ${linkClasses}`}>
                            {repository.url} <span aria-hidden="true">↗</span>
                            <span className="sr-only"> (se deschide într-o filă nouă)</span>
                        </a>

                        {repository.default_branch && (
                            <span>
                                Ramura{' '}
                                <code className="rounded-sm bg-gray-100 px-1.5 py-0.5 font-mono text-gray-900">
                                    {repository.default_branch}
                                </code>
                            </span>
                        )}

                        {repository.last_sha && (
                            <span>
                                Ultimul commit procesat{' '}
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
                            </span>
                        )}
                    </div>
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

            {/* Tab-urile sunt legături obișnuite: se ajunge la ele cu Tab și se deschid cu Enter. */}
            <nav aria-label="Secțiunile repository-ului" className="mt-8 border-b border-gray-200">
                <ul ref={tabListRef} className="-mb-px flex gap-1 overflow-x-auto">
                    {tabs.map((tab) => {
                        const isActive = component === tab.component;

                        return (
                            <li key={tab.component} ref={isActive ? activeTabRef : undefined} className="shrink-0">
                                <Link
                                    href={`/repositories/${repository.id}${tab.path}`}
                                    aria-current={isActive ? 'page' : undefined}
                                    className={`inline-block rounded-t-md border-b-2 px-3 py-2.5 text-sm font-medium whitespace-nowrap focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-indigo-600 ${
                                        isActive
                                            ? 'border-indigo-600 text-indigo-700'
                                            : 'border-transparent text-gray-600 hover:border-gray-300 hover:text-gray-900'
                                    }`}
                                >
                                    {tab.label}
                                </Link>
                            </li>
                        );
                    })}
                </ul>
            </nav>

            <div className="mt-8">{children}</div>
        </AppLayout>
    );
}
