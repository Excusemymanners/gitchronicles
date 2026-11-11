import { Form, Link, usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';
import LastUpdateLine from '@/components/repository/last-update';
import { useActiveChapter } from '@/lib/workspace';
import type { RepositoryPageProps } from '@/types';

type Props = {
    children: ReactNode;
};

const focusRing = 'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent';

const linkClasses = `rounded-sm text-accent hover:text-accent-hover hover:underline ${focusRing}`;

/**
 * Firul paginii pentru paginile care nu au (încă) un capitol în cuprins, de exemplu „Diagrame”
 * înainte să existe vreo diagramă.
 */
const pageTitles: Record<string, string> = {
    'repositories/show': 'Documentație · Prezentare generală',
    'repositories/docs': 'Documentație',
    'repositories/diagrams': 'Arhitectură',
    'repositories/assistant': 'Asistent',
    'repositories/timeline': 'Istoric',
    'repositories/media': 'Media',
};

/**
 * Rama capitolului, în coloana din mijloc a spațiului de lucru (WorkspaceLayout): o coloană de citit
 * de cel mult 760 px, firul paginii („Arhitectură · Straturi”) și, deasupra capitolului, starea
 * procesării sau eroarea ei. Fiecare pagină a unui repository își pune conținutul aici.
 */
export default function RepositoryLayout({ children }: Props) {
    const { props, component } = usePage<RepositoryPageProps>();
    const { auth, repository, can_retry, can_check_updates, can_regenerate, last_update } = props;
    const activeChapter = useActiveChapter();

    const breadcrumb = activeChapter ? `${activeChapter.groupTitle} · ${activeChapter.label}` : (pageTitles[component] ?? null);

    return (
        <div className="mx-auto w-full max-w-190 px-4 pt-6 sm:px-8 sm:pt-8">
            {breadcrumb && <p className="text-xs text-ink-subtle">{breadcrumb}</p>}

            {/* Pe ecranele late, „Actualizat la commitul …”, „Verifică actualizări” și „Regenerează tot” sunt în bara de sus. */}
            <LastUpdateLine
                repository={repository}
                lastUpdate={last_update}
                canCheckUpdates={can_check_updates}
                canRegenerate={can_regenerate}
                className="mt-2 lg:hidden"
            />

            {repository.is_processing && (
                <p
                    role="status"
                    className="mt-4 flex items-center gap-2 rounded-lg border border-info-line bg-info-soft px-4 py-3 text-sm text-info-ink"
                >
                    <span aria-hidden="true" className="size-2 shrink-0 rounded-full bg-info motion-safe:animate-pulse" />
                    Încă se procesează… Capitolele noi apar singure, fără reîncărcarea paginii.
                </p>
            )}

            {repository.status === 'failed' && (
                <div
                    role="alert"
                    className="mt-4 flex flex-col gap-3 rounded-lg border border-danger-line bg-danger-soft p-4 text-sm text-danger-ink sm:flex-row sm:items-center sm:justify-between"
                >
                    <p>
                        <span className="font-semibold">Procesarea a eșuat.</span> {repository.error}
                    </p>

                    {/* Pe ecranele late „Reîncearcă” e în bara de sus; un vizitator e invitat să intre în cont. */}
                    {can_retry ? (
                        <Form action={`/repositories/${repository.id}/retry`} method="post" className="shrink-0 lg:hidden">
                            {({ processing }) => (
                                <button
                                    type="submit"
                                    disabled={processing}
                                    className={`inline-flex cursor-pointer items-center rounded-md bg-accent-strong px-3 py-1.5 text-sm font-semibold text-on-accent hover:bg-accent-strong-hover disabled:cursor-not-allowed disabled:opacity-60 ${focusRing}`}
                                >
                                    {processing ? 'Se pornește…' : 'Reîncearcă'}
                                </button>
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

            <div className="mt-3">{children}</div>
        </div>
    );
}
