import { Head, usePoll } from '@inertiajs/react';
import { useEffect } from 'react';
import DocsContent from '@/components/docs/docs-content';
import DocsPager from '@/components/docs/docs-pager';
import Markdown from '@/components/docs/markdown';
import TimeTravelBar from '@/components/time-travel/time-travel-bar';
import RepositoryLayout from '@/layouts/repository-layout';
import { formatDate, shortSha } from '@/lib/format';
import type { RepositoryItem, RepositoryPageProps } from '@/types';
import type { DocumentLink, DocumentPage } from '@/types/docs';
import type { DocumentTimeTravel } from '@/types/history';

type Props = RepositoryPageProps & {
    /** Capitolele documentației, în ordinea cuprinsului (gol până se scrie prima pagină). */
    navigation: DocumentLink[];
    /** Pagina deschisă: cea din adresă sau, fără slug, „Despre proiect”; `null` cât nu există. */
    document: DocumentPage | null;
    /** Commit-urile procesate și, când în adresă e `?sha=`, textul paginii de la acel commit. */
    time_travel: DocumentTimeTravel;
};

/**
 * Capitolele „Documentație”: o pagină scrisă de GitChronicle (despre proiect, README-ul sau un modul).
 * Cuprinsul e în coloana din stânga a spațiului de lucru, așa că aici e doar pagina, cu legături spre
 * pagina anterioară și următoare la final, ca pe un site de documentație.
 * Cât timp repository-ul se procesează, pagina se reîmprospătează singură, ca textele să apară pe măsură ce sunt scrise.
 * Cu „Călătorie în timp” alegi un commit procesat și vezi textul paginii de atunci.
 */
export default function RepositoriesDocs({ repository, navigation, document, time_travel }: Props) {
    const { start, stop } = usePoll(4000, { only: ['navigation', 'document', 'time_travel'] }, { autoStart: false });

    useEffect(() => {
        if (repository.is_processing) {
            start();
        } else {
            stop();
        }
    }, [repository.is_processing, start, stop]);

    const title = document ? `${document.title} · ${repository.full_name}` : `Documentație · ${repository.full_name}`;

    return (
        <RepositoryLayout>
            <Head title={title} />

            <div className="mt-6">
                {navigation.length === 0 ? (
                    <EmptyState repository={repository} />
                ) : document ? (
                    <>
                        <TimeTravelBar commits={time_travel.commits} selected={time_travel.selected} />
                        {time_travel.selected === null ? (
                            <DocsContent document={document} repositoryFullName={repository.full_name} />
                        ) : (
                            <OldVersion document={document} timeTravel={time_travel} />
                        )}
                        {/* În călătoria în timp, „anterioara” și „următoarea” rămân la același commit. */}
                        <DocsPager
                            items={
                                time_travel.selected === null
                                    ? navigation
                                    : navigation.map((item) => ({ ...item, href: `${item.href}?sha=${time_travel.selected}` }))
                            }
                            currentSlug={document.slug}
                        />
                    </>
                ) : (
                    <p className="rounded-lg border border-dashed border-line-strong px-6 py-10 text-center text-sm text-ink-muted">
                        Pagina „Despre proiect” nu e gata încă. Celelalte pagini sunt în cuprins.
                    </p>
                )}
            </div>
        </RepositoryLayout>
    );
}

/**
 * Pagina așa cum era la commit-ul ales: textul versiunii de atunci, cu commit-ul din care a fost scrisă.
 * O pagină care a apărut mai târziu nu are ce arăta.
 */
function OldVersion({ document, timeTravel }: { document: DocumentPage; timeTravel: DocumentTimeTravel }) {
    const version = timeTravel.version;

    if (version === null) {
        return (
            <p className="rounded-lg border border-dashed border-line-strong px-6 py-10 text-center text-sm text-ink-muted">
                Pagina „{document.title}” nu exista încă la acest commit: a fost scrisă mai târziu.
            </p>
        );
    }

    return (
        <article className="min-w-0">
            <p className="mb-6 border-b border-line pb-4 text-sm text-ink-subtle">
                Versiune scrisă
                {version.sha && (
                    <>
                        {' la commitul '}
                        <code className="rounded-sm bg-surface-2 px-1.5 py-0.5 font-mono text-ink">{shortSha(version.sha)}</code>
                    </>
                )}
                {version.written_at && (
                    <>
                        {' · '}
                        <time dateTime={version.written_at}>{formatDate(version.written_at)}</time>
                    </>
                )}
                {version.same_as_current && ' · pagina nu s-a schimbat de atunci'}
            </p>

            <Markdown>{version.markdown}</Markdown>
        </article>
    );
}

/**
 * Ce arată pagina înainte să existe vreo pagină de documentație: se scrie acum, procesarea a eșuat
 * (motivul e deja deasupra) sau repository-ul a fost procesat înainte să existe documentația.
 */
function EmptyState({ repository }: { repository: RepositoryItem }) {
    if (repository.is_processing) {
        return (
            <p
                role="status"
                className="flex items-center justify-center gap-2 rounded-lg border border-dashed border-line-strong px-6 py-10 text-center text-sm text-ink-muted"
            >
                <span aria-hidden="true" className="size-2 shrink-0 rounded-full bg-info motion-safe:animate-pulse" />
                {repository.status === 'documenting'
                    ? 'Se scrie documentația. Paginile apar aici pe măsură ce sunt gata.'
                    : 'Documentația se scrie după ce se termină clonarea și analiza codului.'}
            </p>
        );
    }

    return (
        <p className="rounded-lg border border-dashed border-line-strong px-6 py-10 text-center text-sm text-ink-muted">
            {repository.status === 'failed'
                ? 'Procesarea s-a oprit înainte să fie scrisă documentația.'
                : 'Documentația acestui repository nu a fost scrisă încă. Apare după următoarea procesare.'}
        </p>
    );
}
