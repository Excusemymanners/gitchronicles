import { Head } from '@inertiajs/react';
import DiagramsContent from '@/components/diagrams/diagrams-content';
import type { DiagramSet } from '@/components/diagrams/types';
import ChapterEmptyState from '@/components/repository/chapter-empty-state';
import TimeTravelBar from '@/components/time-travel/time-travel-bar';
import { useI18n } from '@/hooks/use-i18n';
import RepositoryLayout from '@/layouts/repository-layout';
import { formatNumber } from '@/lib/format';
import type { Completeness, RepositoryPageProps } from '@/types';
import type { DiagramTimeTravel } from '@/types/history';

type Props = RepositoryPageProps & {
    /** Diagramele desenate de BuildDiagrams (Faza 7); în călătoria în timp, cele de la commit-ul ales. */
    diagrams: DiagramSet;
    /** Commit-urile procesate și, când în adresă e `?sha=`, ce e diferit acum față de acel commit. */
    time_travel: DiagramTimeTravel;
};

/**
 * Tab-ul „Diagrame”: fluxurile „Cum funcționează”, straturile și vederile C4 ale repository-ului.
 * Cu „Călătorie în timp” vezi diagramele de la un commit procesat mai demult și ce s-a schimbat de atunci.
 * Un repository gata fără nicio diagramă spune de ce: nu au fost generate încă sau nu are cod de desenat.
 */
export default function RepositoriesDiagrams({ repository, completeness, diagrams, time_travel }: Props) {
    const { t } = useI18n();
    const hasNoDiagram = diagrams.flows.length === 0 && !diagrams.layers && !diagrams.container && !diagrams.component && !diagrams.context;

    if (hasNoDiagram && time_travel.selected === null && repository.status === 'ready') {
        return (
            <RepositoryLayout>
                <Head title={t('diagrams.pageTitle', { repository: repository.full_name })} />
                <div className="mt-6">
                    <NoDiagrams completeness={completeness} />
                </div>
            </RepositoryLayout>
        );
    }

    return (
        <RepositoryLayout>
            <Head title={t('diagrams.pageTitle', { repository: repository.full_name })} />

            <TimeTravelBar commits={time_travel.commits} selected={time_travel.selected} />

            {time_travel.changes && <ChangesSince changes={time_travel.changes} />}

            <DiagramsContent diagrams={diagrams} />
        </RepositoryLayout>
    );
}

/**
 * Fără nicio diagramă: fie repository-ul nu are module cu codul aplicației (doar README, configurări sau
 * teste), deci nu avem ce desena, fie a fost procesat fără etapa diagramelor și o procesare completă le face.
 */
function NoDiagrams({ completeness }: { completeness: Completeness }) {
    const { t } = useI18n();

    if (completeness.artifacts.diagrams.state === 'not_applicable') {
        return (
            <ChapterEmptyState title={t('diagrams.noneTitle')}>
                {t('diagrams.noneText')}
            </ChapterEmptyState>
        );
    }

    return (
        <ChapterEmptyState title={t('diagrams.notGeneratedTitle')} offerRegenerate>
            {t('diagrams.notGeneratedText')}
        </ChapterEmptyState>
    );
}

/** O listă de module, cu „și încă N” când serverul a trimis doar primele. */
function ModuleList({ title, paths, total }: { title: string; paths: string[]; total: number }) {
    const { t, plural } = useI18n();

    return (
        <div>
            <h3 className="text-sm font-medium text-ink">
                {t('diagrams.changes.listTitle', { title, count: formatNumber(total) })}
            </h3>
            <ul className="mt-1.5 flex flex-wrap gap-1.5">
                {paths.map((path) => (
                    <li key={path}>
                        <code className="rounded-sm bg-surface-2 px-1.5 py-0.5 font-mono text-xs break-all text-ink">
                            {path === '/' ? t('diagrams.projectRoot') : path}
                        </code>
                    </li>
                ))}
                {total > paths.length && <li className="text-xs text-ink-subtle">
                        {t('diagrams.moreModules', { count: formatNumber(total - paths.length), modules: plural('modules', total - paths.length) })}
                    </li>}
            </ul>
        </div>
    );
}

/**
 * Ce e diferit acum față de commit-ul ales: diagramele desenate din nou de atunci și modulele (folderele
 * cu cod) care au apărut sau au dispărut. Lista modulelor vine din analiza codului de la cele două commit-uri.
 */
function ChangesSince({ changes }: { changes: NonNullable<DiagramTimeTravel['changes']> }) {
    const { t } = useI18n();
    const modulesKnown = changes.modules_added !== null && changes.modules_removed !== null;
    const sameModules = modulesKnown && changes.modules_added_count === 0 && changes.modules_removed_count === 0;

    return (
        <section aria-labelledby="changes-since-heading" className="mb-8 rounded-lg border border-line bg-surface p-4 shadow-xs">
            <h2 id="changes-since-heading" className="text-base font-semibold text-ink">
                {t('diagrams.changes.heading')}
            </h2>

            <p className="mt-2 text-sm text-ink-muted">
                {changes.diagrams.length === 0
                    ? t('diagrams.changes.same')
                    : t('diagrams.changes.different', { names: changes.diagrams.join(', ') })}
            </p>

            {!modulesKnown && (
                <p className="mt-2 text-sm text-ink-subtle">
                    {t('diagrams.changes.unknownModules')}
                </p>
            )}

            {sameModules && <p className="mt-2 text-sm text-ink-muted">{t('diagrams.changes.sameModules')}</p>}

            {modulesKnown && !sameModules && (
                <div className="mt-3 flex flex-col gap-3">
                    {changes.modules_added !== null && (changes.modules_added_count ?? 0) > 0 && (
                        <ModuleList title={t('diagrams.changes.added')} paths={changes.modules_added} total={changes.modules_added_count ?? 0} />
                    )}
                    {changes.modules_removed !== null && (changes.modules_removed_count ?? 0) > 0 && (
                        <ModuleList title={t('diagrams.changes.removed')} paths={changes.modules_removed} total={changes.modules_removed_count ?? 0} />
                    )}
                </div>
            )}
        </section>
    );
}
