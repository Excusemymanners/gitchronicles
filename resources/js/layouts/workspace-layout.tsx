import { router, usePage, usePoll } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import type { MouseEvent, ReactNode } from 'react';
import AssistantBar from '@/components/assistant/assistant-bar';
import AssistantProvider, { useAssistantConversation } from '@/components/assistant/assistant-provider';
import FlashMessage from '@/components/flash-message';
import { HERO_ASK_ID } from '@/components/repository/ask-box';
import { AskButton, AskPanel } from '@/components/workspace/ask-panel';
import CommandPalette from '@/components/workspace/command-palette';
import DocsNav from '@/components/workspace/docs-nav';
import DocsNavDrawer from '@/components/workspace/docs-nav-drawer';
import { MenuIcon } from '@/components/workspace/icons';
import SectionTabs from '@/components/workspace/section-tabs';
import { repositorySections, sectionOfComponent } from '@/components/workspace/sections';
import { isShortcut } from '@/components/workspace/shortcuts';
import WorkspaceTopBar from '@/components/workspace/workspace-top-bar';
import { useI18n } from '@/hooks/use-i18n';
import { useMediaQuery } from '@/lib/workspace';
import type { RepositoryPageProps, WorkspaceGroup } from '@/types';

type Props = {
    children: ReactNode;
};

/** From "lg" (1024 px) the documentation's pages are a column on the left; below, a drawer. */
const DOCS_COLUMN_QUERY = '(min-width: 64rem)';

/** From "xl" (1280 px) the documentation has a right column for "On this page". */
const ASIDE_COLUMN_QUERY = '(min-width: 80rem)';

/**
 * The workspace of a repository: the top bar and the row of section tabs stay fixed at the top, and the page
 * scrolls below them. It is the persistent layout of the "repositories/…" pages (see app.tsx), so the
 * conversation with the assistant and the open folders survive a change of section.
 *
 * Three page shapes: Documentation keeps a reading layout (its pages on the left, the article, "On this page" on
 * the right); Ask is a centered conversation with its question field at the bottom; every other section is one
 * centered column. On every page but Ask a floating "Ask" button opens the assistant as a panel beside the page.
 */
export default function WorkspaceLayout({ children }: Props) {
    const { repository } = usePage<RepositoryPageProps>().props;

    // The key starts a fresh workspace (and conversation) for another repository.
    return (
        <AssistantProvider key={repository.id} repositoryId={repository.id}>
            <WorkspaceFrame>{children}</WorkspaceFrame>
        </AssistantProvider>
    );
}

function WorkspaceFrame({ children }: Props) {
    const { t } = useI18n();
    const { props, component } = usePage<RepositoryPageProps>();
    const { repository, workspace, can_delete, can_retry, can_check_updates, can_regenerate, last_update } = props;
    const conversation = useAssistantConversation();

    useProcessingPoll(repository);

    const activeSection = sectionOfComponent(component);
    const sections = repositorySections(repository.id, workspace, activeSection);
    const pageShape = component === 'repositories/docs' ? 'docs' : component === 'repositories/assistant' ? 'ask' : 'column';

    const [isAskOpen, setAskOpen] = useState(false);
    const [isPaletteOpen, setPaletteOpen] = useState(false);
    const [isDocsNavOpen, setDocsNavOpen] = useState(false);
    const askButtonRef = useRef<HTMLButtonElement>(null);
    const mainRef = useRef<HTMLElement>(null);

    // One way to ask per screen: no panel on the Ask page, no button while the Overview's own ask box is visible.
    const canUsePanel = pageShape !== 'ask';
    const isHeroAskVisible = useIsVisible(HERO_ASK_ID, component);
    const isPanelOpen = canUsePanel && isAskOpen;

    useEffect(() => {
        function handleKeyDown(event: KeyboardEvent) {
            if (isShortcut(event, 'k')) {
                event.preventDefault();
                setPaletteOpen((isOpen) => !isOpen);
            } else if (isShortcut(event, 'i')) {
                event.preventDefault();

                if (canUsePanel) {
                    setAskOpen((isOpen) => !isOpen);
                } else {
                    document.querySelector<HTMLTextAreaElement>('[data-ask-composer] textarea')?.focus();
                }
            } else if (event.key === 'Escape' && isPanelOpen && document.querySelector('dialog[open]') === null) {
                setAskOpen(false);
            }
        }

        document.addEventListener('keydown', handleKeyDown);

        return () => document.removeEventListener('keydown', handleKeyDown);
    }, [canUsePanel, isPanelOpen]);

    // When the panel closes, the focus goes back to the button that opens it.
    const wasPanelOpen = useRef(false);

    useEffect(() => {
        if (wasPanelOpen.current && !isPanelOpen) {
            askButtonRef.current?.focus();
        }

        wasPanelOpen.current = isPanelOpen;
    }, [isPanelOpen]);

    // "Skip to content" moves the focus to the page without changing the address.
    function skipToContent(event: MouseEvent<HTMLAnchorElement>) {
        event.preventDefault();
        mainRef.current?.focus();
    }

    // The palette's last row asks the assistant: in the panel, or on the Ask page itself.
    const askFromPalette = workspace.assistant.is_indexed
        ? (question: string) => {
              if (canUsePanel) {
                  setAskOpen(true);
              }

              conversation.ask(question);
          }
        : null;

    const main = (
        <main id="continut" ref={mainRef} tabIndex={-1} className="focus:outline-none">
            {children}
        </main>
    );

    return (
        <div className="relative flex h-dvh flex-col overflow-hidden bg-canvas text-ink">
            <a
                href="#continut"
                onClick={skipToContent}
                className="sr-only rounded-md bg-surface-raised px-3 py-2 text-sm font-medium text-accent focus:not-sr-only focus:fixed focus:top-2 focus:left-2 focus:z-50 focus:outline-2 focus:outline-accent"
            >
                {t('workspace.skipToContent')}
            </a>

            <header className="relative z-10 shrink-0 [view-transition-name:workspace-bar]">
                <WorkspaceTopBar
                    repository={repository}
                    canDelete={can_delete}
                    canRetry={can_retry}
                    canCheckUpdates={can_check_updates}
                    canRegenerate={can_regenerate}
                    lastUpdate={last_update}
                    onOpenPalette={() => setPaletteOpen(true)}
                />
                <SectionTabs sections={sections} activeKey={activeSection} />
            </header>

            <div className="relative flex min-h-0 flex-1 flex-col">
                <div className="relative flex min-h-0 flex-1">
                    {pageShape === 'docs' ? (
                        <DocsShape groups={workspace.chapters} isNavOpen={isDocsNavOpen} onOpenNav={() => setDocsNavOpen(true)} onCloseNav={() => setDocsNavOpen(false)}>
                            {main}
                        </DocsShape>
                    ) : (
                        <div className="relative flex min-w-0 flex-1 flex-col">
                            {/* scroll-region: Inertia scrolls this column back to the top on a new page and restores it on "Back". */}
                            <div scroll-region="" className="relative min-h-0 flex-1 overflow-y-auto overscroll-contain [view-transition-name:page]">
                                {main}
                                {/* From 1280 px the button floats over the page: room under it so the last line never sits under it. */}
                                {canUsePanel && <div aria-hidden="true" className="h-20 max-xl:hidden" />}
                            </div>

                            {pageShape === 'ask' && (
                                <div data-ask-composer="" className="shrink-0 border-t border-line bg-canvas">
                                    <div className="mx-auto w-full max-w-190 px-4 pt-3 pb-[max(0.5rem,env(safe-area-inset-bottom))] sm:px-8">
                                        <AssistantBar
                                            assistant={conversation}
                                            repository={repository}
                                            isIndexed={workspace.assistant.is_indexed}
                                            starters={workspace.assistant.starters}
                                        />
                                    </div>
                                </div>
                            )}
                        </div>
                    )}

                    {canUsePanel && <AskPanel repository={repository} assistant={workspace.assistant} isOpen={isPanelOpen} onClose={() => setAskOpen(false)} />}
                </div>

                {/* Below 1280 px the button sits in its own bar under the page, so it never covers the text being read;
                    from 1280 px the bar disappears (contents) and the button floats in the corner. */}
                {canUsePanel && !isHeroAskVisible && (
                    <div className="flex shrink-0 justify-end border-t border-line bg-canvas px-4 py-2 pb-[max(0.5rem,env(safe-area-inset-bottom))] xl:contents">
                        {!isPanelOpen && <AskButton buttonRef={askButtonRef} onClick={() => setAskOpen(true)} />}
                    </div>
                )}
            </div>

            <CommandPalette
                isOpen={isPaletteOpen}
                onClose={() => setPaletteOpen(false)}
                sections={sections}
                groups={workspace.chapters}
                onAsk={askFromPalette}
            />

            <FlashMessage placement="top" />
        </div>
    );
}

type DocsShapeProps = {
    groups: WorkspaceGroup[];
    isNavOpen: boolean;
    onOpenNav: () => void;
    onCloseNav: () => void;
    children: ReactNode;
};

/**
 * The Documentation page's reading layout: its pages and module tree on the left (from 1024 px; a drawer below,
 * opened from a slim bar above the article), the article in the middle and, from 1280 px, the slot on the right
 * where the article's "On this page" list portals itself (components/docs/on-this-page.tsx).
 */
function DocsShape({ groups, isNavOpen, onOpenNav, onCloseNav, children }: DocsShapeProps) {
    const { t } = useI18n();
    const hasNavColumn = useMediaQuery(DOCS_COLUMN_QUERY);
    const hasAsideColumn = useMediaQuery(ASIDE_COLUMN_QUERY);
    const docsGroups = documentationGroups(groups);

    return (
        <>
            {hasNavColumn && (
                <div className="relative w-66 shrink-0 overflow-y-auto overscroll-contain border-r border-line bg-surface">
                    <DocsNav groups={docsGroups} />
                </div>
            )}

            <div className="relative flex min-w-0 flex-1 flex-col">
                {!hasNavColumn && (
                    <div className="shrink-0 border-b border-line bg-canvas px-2 py-1.5 sm:px-4">
                        <button
                            type="button"
                            onClick={onOpenNav}
                            aria-haspopup="dialog"
                            aria-expanded={isNavOpen}
                            className="inline-flex cursor-pointer items-center gap-2 rounded-md px-2 py-1.5 text-sm font-medium text-ink-muted hover:bg-surface-2 hover:text-ink focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
                        >
                            <MenuIcon className="size-4" />
                            {t('workspace.docsNav.label')}
                        </button>
                    </div>
                )}

                <div scroll-region="" className="relative min-h-0 flex-1 overflow-y-auto overscroll-contain [view-transition-name:page]">
                    {children}
                    <div aria-hidden="true" className="h-20" />
                </div>
            </div>

            {hasAsideColumn && (
                <div className="relative w-64 shrink-0 overflow-y-auto overscroll-contain border-l border-line bg-surface">
                    <div id="workspace-aside-top" className="sticky top-0 z-10" />
                </div>
            )}

            {!hasNavColumn && <DocsNavDrawer groups={docsGroups} isOpen={isNavOpen} onClose={onCloseNav} />}
        </>
    );
}

/** The documentation's part of the server's table of contents: its own pages (under /docs) and the modules. */
function documentationGroups(groups: WorkspaceGroup[]): WorkspaceGroup[] {
    return groups
        .filter((group) => group.key === 'docs' || group.key === 'modules')
        .map((group) => ({ ...group, chapters: group.chapters.filter((chapter) => chapter.href.includes('/docs')) }))
        .filter((group) => group.chapters.length > 0 || group.folders.length > 0);
}

/**
 * While the repository is processed, ask the server every 2 seconds for the repository and the "Try again" right
 * only; stop when it is ready or failed. When the stage changes, reload the whole page: a finished stage brings new
 * sections and data.
 */
function useProcessingPoll(repository: RepositoryPageProps['repository']): void {
    const { start, stop } = usePoll(2000, { only: ['repository', 'can_retry'] }, { autoStart: false });

    useEffect(() => {
        if (repository.is_processing) {
            start();
        } else {
            stop();
        }
    }, [repository.is_processing, start, stop]);

    const shownStatus = useRef(repository.status);

    useEffect(() => {
        if (shownStatus.current !== repository.status) {
            shownStatus.current = repository.status;
            router.reload();
        }
    }, [repository.status]);
}

/**
 * Whether the element with this id is on screen (an IntersectionObserver, which also respects the scroll
 * column's clipping). Looked up again when the page changes; an element that does not exist is "not visible".
 */
function useIsVisible(elementId: string, pageComponent: string): boolean {
    const [isVisible, setVisible] = useState(false);

    useEffect(() => {
        const element = document.getElementById(elementId);

        if (!element) {
            setVisible(false);

            return;
        }

        const observer = new IntersectionObserver(([entry]) => setVisible(entry.isIntersecting));
        observer.observe(element);

        return () => observer.disconnect();
    }, [elementId, pageComponent]);

    return isVisible;
}
