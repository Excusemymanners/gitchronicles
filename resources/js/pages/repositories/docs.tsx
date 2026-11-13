import { Head, usePoll } from '@inertiajs/react';
import { useEffect } from 'react';
import DocsContent from '@/components/docs/docs-content';
import DocsPager from '@/components/docs/docs-pager';
import Markdown from '@/components/docs/markdown';
import ChapterEmptyState from '@/components/repository/chapter-empty-state';
import TimeTravelBar from '@/components/time-travel/time-travel-bar';
import { useI18n } from '@/hooks/use-i18n';
import RepositoryLayout from '@/layouts/repository-layout';
import { shortSha } from '@/lib/format';
import { formatDay } from '@/lib/history-format';
import type { Completeness, RepositoryItem, RepositoryPageProps } from '@/types';
import type { DocumentLink, DocumentPage, DocumentVisuals } from '@/types/docs';
import type { DocumentTimeTravel } from '@/types/history';

type Props = RepositoryPageProps & {
    /** Capitolele documentației, în ordinea cuprinsului (gol până se scrie prima pagină). */
    navigation: DocumentLink[];
    /** Pagina deschisă: cea din adresă sau, fără slug, „Despre proiect”; `null` cât nu există. */
    document: DocumentPage | null;
    /** Pozele din jurul textului (din README, diagrame, cifre); `null` în călătoria în timp. */
    visuals: DocumentVisuals | null;
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
export default function RepositoriesDocs({ repository, completeness, navigation, document, visuals, time_travel }: Props) {
    const { t } = useI18n();
    const { start, stop } = usePoll(4000, { only: ['navigation', 'document', 'visuals', 'time_travel'] }, { autoStart: false });

    useEffect(() => {
        if (repository.is_processing) {
            start();
        } else {
            stop();
        }
    }, [repository.is_processing, start, stop]);

    const title = document ? `${document.title} · ${repository.full_name}` : t('docs.pageTitle', { repository: repository.full_name });

    return (
        <RepositoryLayout>
            <Head title={title} />

            <div className="mt-6">
                {navigation.length === 0 ? (
                    <EmptyState repository={repository} completeness={completeness} />
                ) : document ? (
                    <>
                        <TimeTravelBar commits={time_travel.commits} selected={time_travel.selected} />
                        {time_travel.selected === null ? (
                            <DocsContent document={document} repositoryFullName={repository.full_name} visuals={visuals} />
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
                        {t('docs.notReady')}
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
    const { t, rich } = useI18n();
    const version = timeTravel.version;

    if (version === null) {
        return (
            <p className="rounded-lg border border-dashed border-line-strong px-6 py-10 text-center text-sm text-ink-muted">
                {t('docs.pageMissingAtCommit', { title: document.title })}
            </p>
        );
    }

    return (
        <article className="min-w-0">
            <p className="mb-8 border-b border-line pb-4 text-[0.8125rem] text-ink-subtle">
                {version.sha
                    ? rich('docs.version.writtenAtCommit', {
                          sha: <code className="rounded-sm bg-surface-2 px-1.5 py-0.5 font-mono text-ink">{shortSha(version.sha)}</code>,
                      })
                    : t('docs.version.written')}
                {version.written_at && (
                    <>
                        {' · '}
                        <time dateTime={version.written_at}>{formatDay(version.written_at)}</time>
                    </>
                )}
                {version.same_as_current && ` · ${t('docs.version.unchanged')}`}
            </p>

            <Markdown>{version.markdown}</Markdown>
        </article>
    );
}

/**
 * Ce arată pagina înainte să existe vreo pagină de documentație: se scrie acum, procesarea a eșuat
 * (motivul e deja deasupra) sau repository-ul a fost procesat fără etapa documentației (de exemplu de o
 * versiune mai veche); atunci oferim „Regenerează tot”.
 */
function EmptyState({ repository, completeness }: { repository: RepositoryItem; completeness: Completeness }) {
    const { t } = useI18n();

    if (repository.is_processing) {
        return (
            <p
                role="status"
                className="flex items-center justify-center gap-2 rounded-lg border border-dashed border-line-strong px-6 py-10 text-center text-sm text-ink-muted"
            >
                <span aria-hidden="true" className="size-2 shrink-0 rounded-full bg-info motion-safe:animate-pulse" />
                {repository.status === 'documenting'
                    ? t('docs.empty.writing')
                    : t('docs.empty.waiting')}
            </p>
        );
    }

    if (repository.status === 'failed') {
        return (
            <ChapterEmptyState title={t('docs.empty.failedTitle')}>
                {t('docs.empty.failedText')}
            </ChapterEmptyState>
        );
    }

    return (
        <ChapterEmptyState title={t('docs.empty.missingTitle')} offerRegenerate={completeness.artifacts.documentation.state === 'missing'}>
            {t('docs.empty.missingText')}
        </ChapterEmptyState>
    );
}
