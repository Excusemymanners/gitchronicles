import { Link } from '@inertiajs/react';
import type { ReactNode } from 'react';
import LanguageBar from '@/components/home/language-bar';
import { AssistantIcon, DiagramIcon, DocumentIcon, VideoIcon } from '@/components/icons';
import PipelineRail from '@/components/pipeline-rail';
import StatusBadge from '@/components/status-badge';
import { countLabel, formatDate, formatRelative, shortSha } from '@/lib/format';
import type { RepositoryCard as RepositoryCardData } from '@/types/home';

type Props = {
    repository: RepositoryCardData;
};

const focusRing = 'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent';

/**
 * Un repository în galeria de pe prima pagină. Tot cardul duce la pagina repository-ului (legătura din titlu
 * e întinsă peste card cu after:absolute after:inset-0); legăturile către capitole stau deasupra ei (relative z-10).
 *
 * Ce arată depinde de status, mereu din date reale:
 *  - gata: limbajele, numerele din analiză, capitolele care există și ultimul commit procesat;
 *  - în lucru: progresul prin pașii procesării (cardul se actualizează singur, vezi pages/home.tsx);
 *  - eșuat: ce nu a mers.
 */
export default function RepositoryCard({ repository }: Props) {
    const { facts } = repository;
    const href = `/repositories/${repository.id}`;
    const isReady = repository.status === 'ready' || repository.status === 'updating';

    return (
        <li className="relative flex flex-col rounded-lg border border-line bg-surface p-5 shadow-card transition-colors hover:border-line-strong has-[h3_a:active]:bg-surface-2 sm:p-6">
            <div className="flex items-start justify-between gap-3">
                <h3 className="min-w-0">
                    <Link href={href} prefetch className={`group block rounded-sm after:absolute after:inset-0 after:rounded-lg ${focusRing}`}>
                        <span className="block truncate text-[0.8125rem] text-ink-subtle">{repository.owner}</span>
                        {/* Același nume de tranziție ca titlul din bara spațiului de lucru: la deschidere, numele alunecă acolo. */}
                        <span
                            style={{ viewTransitionName: `repo-title-${repository.id}` }}
                            className="block font-display text-xl font-semibold tracking-tight break-words text-ink transition-colors group-hover:text-accent"
                        >
                            {repository.name}
                        </span>
                    </Link>
                </h3>

                {/* „Gata” e starea obișnuită, așa că nu o mai anunțăm; eticheta apare doar pentru celelalte. */}
                {repository.status !== 'ready' && <StatusBadge status={repository.status} label={repository.status_label} />}
            </div>

            {repository.description && <p className="mt-2 line-clamp-2 text-sm leading-6 text-ink-muted">{repository.description}</p>}

            {repository.status === 'failed' && (
                <p className="mt-4 line-clamp-3 rounded-md bg-danger-soft px-3 py-2 text-sm text-danger-ink">
                    {repository.error ?? 'Procesarea s-a oprit cu o eroare.'}
                </p>
            )}

            {repository.is_processing && !isReady && (
                <div className="mt-5">
                    <PipelineRail status={repository.status} variant="compact" />
                    <p className="mt-3 text-[0.8125rem] text-ink-muted">Cardul se actualizează singur până la „Gata”.</p>
                </div>
            )}

            {isReady && (
                <>
                    {facts.languages.length > 0 && (
                        <div className="mt-5">
                            <LanguageBar languages={facts.languages} />
                        </div>
                    )}

                    <ul className="mt-5 flex flex-wrap gap-x-6 gap-y-1 text-[0.8125rem] text-ink-muted">
                        <Fact label={countLabel(facts.file_count, 'fișier', 'fișiere')} />
                        <Fact label={countLabel(facts.module_count, 'modul', 'module')} />
                        {facts.commit_count > 0 && <Fact label={countLabel(facts.commit_count, 'commit', 'commit-uri')} />}
                    </ul>

                    {/* mt-auto ține subsolul jos, ca două carduri alăturate să se termine la fel. */}
                    <div className="mt-auto pt-5">
                        <div className="flex flex-wrap items-center justify-between gap-x-4 gap-y-3 border-t border-line pt-4">
                            <Chapters repositoryId={repository.id} facts={facts} />

                            {repository.last_sha && (
                                <p className="text-[0.8125rem] text-ink-subtle">
                                    <span className="sr-only">Ultimul commit procesat: </span>
                                    <code title={repository.last_sha} className="font-mono text-[0.75rem] text-ink-muted">
                                        {shortSha(repository.last_sha)}
                                    </code>
                                    {facts.processed_at && (
                                        <>
                                            , procesat{' '}
                                            <time dateTime={facts.processed_at} title={formatDate(facts.processed_at)}>
                                                {formatRelative(facts.processed_at)}
                                            </time>
                                        </>
                                    )}
                                </p>
                            )}
                        </div>
                    </div>
                </>
            )}
        </li>
    );
}

/** Un număr din analiză cu substantivul lui („94 de fișiere”): numărul iese în evidență, restul rămâne discret. */
function Fact({ label }: { label: string }) {
    const [count, ...noun] = label.split(' ');

    return (
        <li>
            <span className="font-medium text-ink tabular-nums">{count}</span> {noun.join(' ')}
        </li>
    );
}

type ChaptersProps = {
    repositoryId: number;
    facts: RepositoryCardData['facts'];
};

/** Legături directe către capitolele care există deja pentru repository. */
function Chapters({ repositoryId, facts }: ChaptersProps) {
    const base = `/repositories/${repositoryId}`;
    const hasChapters = facts.document_count > 0 || facts.diagram_count > 0 || facts.has_assistant || facts.has_video;

    if (!hasChapters) {
        return <p className="text-[0.8125rem] text-ink-subtle">Deocamdată are doar structura codului.</p>;
    }

    return (
        <ul className="flex flex-wrap gap-1.5">
            {facts.document_count > 0 && (
                <ChapterLink href={`${base}/docs`} icon={<DocumentIcon />} label="Documentație" count={facts.document_count} noun="pagini" />
            )}
            {facts.diagram_count > 0 && (
                <ChapterLink href={`${base}/diagrams`} icon={<DiagramIcon />} label="Diagrame" count={facts.diagram_count} noun="diagrame" />
            )}
            {facts.has_assistant && <ChapterLink href={`${base}/assistant`} icon={<AssistantIcon />} label="Asistent" />}
            {facts.has_video && <ChapterLink href={`${base}/media`} icon={<VideoIcon />} label="Video" />}
        </ul>
    );
}

type ChapterLinkProps = {
    href: string;
    icon: ReactNode;
    label: string;
    /** Câte pagini sau diagrame are capitolul; lipsește unde nu are sens un număr. */
    count?: number;
    /** Ce numără `count`, pentru cititoarele de ecran („25 pagini”). */
    noun?: string;
};

function ChapterLink({ href, icon, label, count, noun }: ChapterLinkProps) {
    return (
        <li>
            <Link
                href={href}
                className={`pressable relative z-10 inline-flex items-center gap-1.5 rounded-md bg-surface-2 px-2 py-1 text-[0.8125rem] font-medium text-ink-muted hover:bg-accent-soft hover:text-accent ${focusRing}`}
            >
                {icon}
                {label}
                {count !== undefined && (
                    <span className="tabular-nums text-ink-subtle">
                        {count}
                        <span className="sr-only"> {noun}</span>
                    </span>
                )}
            </Link>
        </li>
    );
}
