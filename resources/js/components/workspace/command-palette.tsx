import { router } from '@inertiajs/react';
import { useEffect, useId, useRef, useState } from 'react';
import type { KeyboardEvent } from 'react';
import { AskIcon, SearchIcon } from '@/components/workspace/icons';
import type { Section } from '@/components/workspace/sections';
import { useI18n } from '@/hooks/use-i18n';
import { splitHref } from '@/lib/workspace';
import type { WorkspaceGroup } from '@/types';

type Props = {
    isOpen: boolean;
    onClose: () => void;
    sections: Section[];
    /** The server's table of contents (WorkspaceResource): the documentation pages and the modules come from here. */
    groups: WorkspaceGroup[];
    /** Asks the assistant a question; `null` while the code is not indexed (the last row is then left out). */
    onAsk: ((question: string) => void) | null;
};

type Item = {
    id: string;
    label: string;
    /** What kind of place it is: "Section", "Documentation", "Module". */
    kind: string;
    href: string;
};

/** At most this many rows are drawn: a big repository has hundreds of modules, and the filter narrows them. */
const SHOWN_ITEMS = 40;

/**
 * The command palette (Ctrl/Cmd+K): one field that finds any section, documentation page or module of the
 * repository and opens it, from data the page already has (no request while typing). Arrows move, Enter opens,
 * Esc closes. The last row, "Ask the assistant: «…»", sends what was typed as a question.
 *
 * It follows the combobox pattern: the focus stays in the field and aria-activedescendant names the highlighted
 * row, so a screen reader reads each row as the arrows move.
 */
export default function CommandPalette({ isOpen, onClose, sections, groups, onAsk }: Props) {
    const { t } = useI18n();
    const dialogRef = useRef<HTMLDialogElement>(null);
    const listId = useId();
    const [query, setQuery] = useState('');
    const [activeIndex, setActiveIndex] = useState(0);

    useEffect(() => {
        const dialog = dialogRef.current;

        if (!dialog) {
            return;
        }

        if (isOpen && !dialog.open) {
            setQuery('');
            setActiveIndex(0);
            dialog.showModal();
        } else if (!isOpen && dialog.open) {
            dialog.close();
        }
    }, [isOpen]);

    const items: Item[] = [
        ...sections.map((section) => ({ id: `section-${section.key}`, label: section.label, kind: t('workspace.palette.section'), href: section.href })),
        ...groups
            .filter((group) => group.key === 'docs')
            .flatMap((group) => group.chapters.filter((chapter) => chapter.href.includes('/docs')))
            .map((chapter) => ({ id: `docs-${chapter.href}`, label: chapter.label, kind: t('workspace.palette.page'), href: chapter.href })),
        ...groups
            .flatMap((group) => group.folders)
            .flatMap((folder) => folder.modules)
            .map((module) => ({ id: `module-${module.id}`, label: module.path === '/' ? t('workspace.root') : module.path, kind: t('workspace.palette.module'), href: module.href })),
    ];

    const normalizedQuery = query.trim().toLowerCase();
    const matches = (normalizedQuery === '' ? items : items.filter((item) => item.label.toLowerCase().includes(normalizedQuery))).slice(0, SHOWN_ITEMS);
    const canAsk = onAsk !== null && normalizedQuery.length >= 3;
    const rowCount = matches.length + (canAsk ? 1 : 0);
    const safeIndex = Math.min(activeIndex, Math.max(rowCount - 1, 0));

    function open(index: number) {
        if (index === matches.length && canAsk) {
            onClose();
            onAsk(query.trim());

            return;
        }

        const item = matches[index];

        if (!item) {
            return;
        }

        onClose();
        const [path, hash] = splitHref(item.href);

        // A section of the open page is only an anchor: the browser scrolls to it without a new visit.
        if (hash !== '' && path === window.location.pathname) {
            window.location.hash = hash;
        } else {
            router.visit(item.href);
        }
    }

    function handleKeyDown(event: KeyboardEvent<HTMLInputElement>) {
        if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
            event.preventDefault();
            const step = event.key === 'ArrowDown' ? 1 : -1;
            setActiveIndex(rowCount === 0 ? 0 : (safeIndex + step + rowCount) % rowCount);
        } else if (event.key === 'Enter') {
            event.preventDefault();
            open(safeIndex);
        }
    }

    // Keep the highlighted row visible while the arrows move through a long list.
    useEffect(() => {
        dialogRef.current?.querySelector(`[data-row="${safeIndex}"]`)?.scrollIntoView({ block: 'nearest' });
    }, [safeIndex]);

    const rowClasses = (index: number) =>
        `flex w-full cursor-pointer items-center gap-3 rounded-md px-3 py-2 text-left text-sm ${index === safeIndex ? 'bg-accent-soft text-ink' : 'text-ink'}`;

    return (
        <dialog
            ref={dialogRef}
            aria-label={t('workspace.palette.label')}
            onClose={onClose}
            onClick={(event) => {
                if (event.target === dialogRef.current) {
                    onClose();
                }
            }}
            className="mx-auto mt-[12dvh] w-[min(36rem,calc(100vw-2rem))] max-w-none overflow-hidden rounded-xl border border-line bg-surface-raised p-0 text-ink shadow-float backdrop:bg-canvas/80 backdrop:backdrop-blur-sm open:motion-safe:animate-rise"
        >
            <div className="flex items-center gap-3 border-b border-line px-4">
                <SearchIcon className="size-4 shrink-0 text-ink-subtle" />
                <input
                    type="text"
                    role="combobox"
                    aria-expanded="true"
                    aria-controls={listId}
                    aria-activedescendant={rowCount > 0 ? `${listId}-${safeIndex}` : undefined}
                    aria-autocomplete="list"
                    aria-label={t('workspace.palette.label')}
                    value={query}
                    onChange={(event) => {
                        setQuery(event.target.value);
                        setActiveIndex(0);
                    }}
                    onKeyDown={handleKeyDown}
                    placeholder={t('workspace.palette.placeholder')}
                    autoComplete="off"
                    spellCheck={false}
                    className="h-12 min-w-0 flex-1 bg-transparent text-[0.9375rem] text-ink placeholder:text-ink-subtle focus:outline-none"
                />
                <kbd className="font-sans text-xs text-ink-subtle">{t('workspace.palette.escKey')}</kbd>
            </div>

            <ul id={listId} role="listbox" aria-label={t('workspace.palette.results')} className="max-h-[min(24rem,60dvh)] overflow-y-auto p-1.5">
                {matches.map((item, index) => (
                    <li
                        key={item.id}
                        id={`${listId}-${index}`}
                        role="option"
                        aria-selected={index === safeIndex}
                        data-row={index}
                        onMouseMove={() => setActiveIndex(index)}
                        onClick={() => open(index)}
                        className={rowClasses(index)}
                    >
                        <span className="min-w-0 flex-1 truncate">{item.label}</span>
                        <span className="shrink-0 text-xs text-ink-subtle">{item.kind}</span>
                    </li>
                ))}

                {canAsk && (
                    <li
                        id={`${listId}-${matches.length}`}
                        role="option"
                        aria-selected={matches.length === safeIndex}
                        data-row={matches.length}
                        onMouseMove={() => setActiveIndex(matches.length)}
                        onClick={() => open(matches.length)}
                        className={`${rowClasses(matches.length)} ${matches.length > 0 ? 'mt-1' : ''}`}
                    >
                        <AskIcon className="size-4 shrink-0" />
                        <span className="min-w-0 flex-1 truncate">{t('workspace.palette.ask', { question: query.trim() })}</span>
                    </li>
                )}

                {rowCount === 0 && <li className="px-3 py-6 text-center text-sm text-ink-muted">{t('workspace.palette.empty')}</li>}
            </ul>
        </dialog>
    );
}
