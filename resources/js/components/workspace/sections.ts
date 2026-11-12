import { translate } from '@/lib/i18n';
import type { TranslationKey } from '@/lang';
import type { Workspace } from '@/types';

/** The sections of a repository, in the order of the tab row. */
export type SectionKey = 'overview' | 'docs' | 'diagrams' | 'ask' | 'history' | 'media' | 'report';

export type Section = {
    key: SectionKey;
    label: string;
    href: string;
};

type SectionDefinition = {
    key: SectionKey;
    labelKey: TranslationKey;
    /** The page's path after /repositories/{id}; '' is the Overview. */
    path: string;
    /** The Inertia page component that renders it, to know which tab is active. */
    component: string;
    /** Whether the section has something to show for this repository (the Overview always has). */
    exists: (workspace: Workspace) => boolean;
};

/** Whether a chapter group of the server's table of contents (WorkspaceResource) exists. */
function hasGroup(workspace: Workspace, key: string): boolean {
    return workspace.chapters.some((group) => group.key === key);
}

/** Whether at least one documentation page was written: a chapter or a module that opens a /docs page. */
function hasDocumentation(workspace: Workspace): boolean {
    return workspace.chapters.some(
        (group) =>
            group.chapters.some((chapter) => chapter.href.includes('/docs')) ||
            group.folders.some((folder) => folder.modules.some((module) => module.href.includes('/docs'))),
    );
}

const SECTIONS: SectionDefinition[] = [
    { key: 'overview', labelKey: 'workspace.sections.overview', path: '', component: 'repositories/show', exists: () => true },
    { key: 'docs', labelKey: 'workspace.sections.docs', path: '/docs', component: 'repositories/docs', exists: hasDocumentation },
    { key: 'diagrams', labelKey: 'workspace.sections.diagrams', path: '/diagrams', component: 'repositories/diagrams', exists: (workspace) => hasGroup(workspace, 'architecture') },
    { key: 'ask', labelKey: 'workspace.sections.ask', path: '/assistant', component: 'repositories/assistant', exists: (workspace) => workspace.assistant.is_indexed },
    { key: 'history', labelKey: 'workspace.sections.history', path: '/timeline', component: 'repositories/timeline', exists: (workspace) => hasGroup(workspace, 'history') },
    { key: 'media', labelKey: 'workspace.sections.media', path: '/media', component: 'repositories/media', exists: (workspace) => hasGroup(workspace, 'media') },
    // The server sends the report card for a ready repository even before the first report, so a user can start it.
    { key: 'report', labelKey: 'workspace.sections.report', path: '/consulting', component: 'repositories/consulting', exists: (workspace) => workspace.elements.consulting !== null },
];

/** The section a page component belongs to, or null for a page outside the tab row. */
export function sectionOfComponent(component: string): SectionKey | null {
    return SECTIONS.find((section) => section.component === component)?.key ?? null;
}

/**
 * The tabs of a repository: every section whose page has something to show, plus the open one
 * (a visitor can reach an empty section by its address; its tab must still say where they are).
 */
export function repositorySections(repositoryId: number, workspace: Workspace, activeKey: SectionKey | null): Section[] {
    return SECTIONS.filter((section) => section.key === activeKey || section.exists(workspace)).map((section) => ({
        key: section.key,
        label: translate(section.labelKey),
        href: `/repositories/${repositoryId}${section.path}`,
    }));
}
