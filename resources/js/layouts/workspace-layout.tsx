import { router, usePage, usePoll } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import type { MouseEvent, ReactNode } from 'react';
import AssistantProvider from '@/components/assistant/assistant-provider';
import FlashMessage from '@/components/flash-message';
import AssistantDock from '@/components/workspace/assistant-dock';
import ChaptersDrawer from '@/components/workspace/chapters-drawer';
import ChaptersNav from '@/components/workspace/chapters-nav';
import ElementsPanel from '@/components/workspace/elements-panel';
import WorkspaceTopBar from '@/components/workspace/workspace-top-bar';
import { useMediaQuery } from '@/lib/workspace';
import type { RepositoryPageProps } from '@/types';

type Props = {
    children: ReactNode;
};

/** De la „lg” (1024 px) cuprinsul e o coloană în stânga; mai jos e un sertar. */
const CHAPTERS_COLUMN_QUERY = '(min-width: 64rem)';

/** De la „xl” (1280 px) panoul „Elemente” e o coloană în dreapta; mai jos e sub conținutul capitolului. */
const ELEMENTS_COLUMN_QUERY = '(min-width: 80rem)';

/**
 * Spațiul de lucru al unui repository, în stilul NotebookLM: bara de sus, cuprinsul în stânga (ca pe
 * Wikipedia sau pe un site de documentație), capitolul deschis în mijloc, panoul „Elemente” în dreapta
 * și bara asistentului mereu jos. Fiecare coloană se derulează separat; pagina întreagă stă pe loc.
 *
 * E layout-ul implicit al paginilor „repositories/…” (vezi app.tsx), deci Inertia îl păstrează montat
 * între capitole: cuprinsul își păstrează derularea și folderele deschise, iar conversația cu asistentul
 * rămâne când deschizi alt capitol. Pagina însăși își pune conținutul în RepositoryLayout (rama capitolului).
 *
 * Tot aici e reîmprospătarea cât timp repository-ul se procesează (mutată din fosta bară de tab-uri).
 */
export default function WorkspaceLayout({ children }: Props) {
    const { props, component } = usePage<RepositoryPageProps>();
    const { repository, workspace, can_delete, can_retry, can_check_updates, can_regenerate, last_update } = props;

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
    // o etapă terminată aduce capitole și elemente noi (prop-ul „workspace”) și date pentru capitol.
    const shownStatus = useRef(repository.status);

    useEffect(() => {
        if (shownStatus.current !== repository.status) {
            shownStatus.current = repository.status;
            router.reload();
        }
    }, [repository.status]);

    const hasChaptersColumn = useMediaQuery(CHAPTERS_COLUMN_QUERY);
    const hasElementsColumn = useMediaQuery(ELEMENTS_COLUMN_QUERY);
    const [isChaptersDrawerOpen, setChaptersDrawerOpen] = useState(false);

    // „Sari la conținut” mută focusul pe capitol fără să schimbe adresa (o ancoră ar fi schimbat capitolul evidențiat).
    const mainRef = useRef<HTMLElement>(null);

    function skipToContent(event: MouseEvent<HTMLAnchorElement>) {
        event.preventDefault();
        mainRef.current?.focus();
    }

    return (
        // Cheia: alt repository pornește cu un spațiu de lucru nou (foldere închise, fără conversație).
        // AssistantProvider ține conversația, comună barei de jos și paginii „Asistent”.
        <AssistantProvider key={repository.id} repositoryId={repository.id}>
            {/*
              Fiecare coloană care se derulează e „relative”: textele sr-only (poziționate absolut) rămân în coloana lor;
              altfel ar ieși din ea și ar face să se deruleze toată rama, cu bara de sus cu tot.
            */}
            <div className="relative flex h-dvh flex-col overflow-hidden bg-canvas text-ink">
                <a
                    href="#continut"
                    onClick={skipToContent}
                    className="sr-only rounded-md bg-surface-raised px-3 py-2 text-sm font-medium text-accent focus:not-sr-only focus:fixed focus:top-2 focus:left-2 focus:z-50 focus:outline-2 focus:outline-accent"
                >
                    Sari la conținut
                </a>

                <WorkspaceTopBar
                    repository={repository}
                    canDelete={can_delete}
                    canRetry={can_retry}
                    canCheckUpdates={can_check_updates}
                    canRegenerate={can_regenerate}
                    lastUpdate={last_update}
                    onOpenChapters={hasChaptersColumn ? null : () => setChaptersDrawerOpen(true)}
                    isChaptersOpen={isChaptersDrawerOpen}
                />

                <div className="flex min-h-0 flex-1">
                    {hasChaptersColumn && (
                        <div className="relative w-66 shrink-0 overflow-y-auto overscroll-contain border-r border-line bg-surface">
                            <ChaptersNav groups={workspace.chapters} />
                        </div>
                    )}

                    <div className="relative flex min-w-0 flex-1 flex-col">
                        {/* scroll-region: Inertia readuce derularea acestei coloane sus la un capitol nou și o reface la „Înapoi”. */}
                        <div scroll-region="" className="relative min-h-0 flex-1 overflow-y-auto overscroll-contain">
                            <main id="continut" ref={mainRef} tabIndex={-1} className="focus:outline-none">
                                {children}
                            </main>

                            {!hasElementsColumn && <ElementsPanel elements={workspace.elements} placement="below" />}

                            {/* Loc liber sub capitol, cât bara asistentului, ca ultimul rând să nu rămână sub ea. */}
                            <div aria-hidden="true" className="h-52" />
                        </div>

                        {/* Pagina „Asistent” arată ea conversația, pe toată înălțimea; aici rămâne doar bara. */}
                        <AssistantDock
                            repository={repository}
                            assistant={workspace.assistant}
                            showThread={component !== 'repositories/assistant'}
                        />
                    </div>

                    {hasElementsColumn && (
                        <div className="relative w-82 shrink-0 overflow-y-auto overscroll-contain border-l border-line bg-surface">
                            <ElementsPanel elements={workspace.elements} placement="column" />
                        </div>
                    )}
                </div>

                {!hasChaptersColumn && (
                    <ChaptersDrawer
                        repository={repository}
                        groups={workspace.chapters}
                        canDelete={can_delete}
                        isOpen={isChaptersDrawerOpen}
                        onClose={() => setChaptersDrawerOpen(false)}
                    />
                )}

                <FlashMessage placement="top" />
            </div>
        </AssistantProvider>
    );
}
