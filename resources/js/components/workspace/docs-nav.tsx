import { useEffect, useId, useRef, useState } from 'react';
import type { ReactNode, Ref } from 'react';
import ChapterLink from '@/components/workspace/chapter-link';
import { ChevronIcon, SearchIcon } from '@/components/workspace/icons';
import { useI18n } from '@/hooks/use-i18n';
import { formatNumber } from '@/lib/format';
import { revealInScrollParent, useActiveChapter } from '@/lib/workspace';
import type { WorkspaceGroup, WorkspaceModule, WorkspaceModuleFolder } from '@/types';

type Props = {
    groups: WorkspaceGroup[];
};

/** The filter field appears when there are more modules than this: a big project can have hundreds. */
const FILTER_ABOVE_MODULE_COUNT = 12;

const focusRing = 'focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-accent';

const itemClasses = `flex w-full items-center gap-2 rounded-lg px-2.5 py-1.5 text-left text-sm motion-safe:transition-colors ${focusRing}`;

const idleItemClasses = 'text-ink-muted hover:bg-surface-2 hover:text-ink';

/** Capitolul deschis: textul și fundalul în culoarea accentului și o bară subțire în stânga, ca în cuprinsul unei documentații. */
const activeItemClasses = 'bg-accent-soft font-medium text-accent shadow-[inset_2px_0_0_var(--color-accent)]';

/**
 * The documentation's own navigation (the left column of the Documentation page, a drawer on phones): its pages
 * ("About the project", the README) and the module tree, with the open page highlighted. Modules are grouped by
 * their top folder, in folders that open and close, like a documentation site.
 */
export default function DocsNav({ groups }: Props) {
    const { t } = useI18n();
    const activeChapter = useActiveChapter();

    // Când capitolul deschis se schimbă (de exemplu dintr-un card din dreapta), îl aducem în vedere în cuprins.
    const activeItemRef = useRef<HTMLLIElement>(null);

    useEffect(() => {
        revealInScrollParent(activeItemRef.current, 'y');
    }, [activeChapter?.href]);

    return (
        <nav aria-label={t('workspace.docsNav.label')} className="flex flex-col gap-6 px-3 pt-5 pb-10">
            {groups.map((group) => (
                <ChapterGroup key={group.key} group={group} activeHref={activeChapter?.href ?? null} activeItemRef={activeItemRef} />
            ))}
        </nav>
    );
}

type ChapterGroupProps = {
    group: WorkspaceGroup;
    activeHref: string | null;
    activeItemRef: Ref<HTMLLIElement>;
};

function ChapterGroup({ group, activeHref, activeItemRef }: ChapterGroupProps) {
    const headingId = useId();

    return (
        <section aria-labelledby={headingId}>
            <h2 id={headingId} className="px-2.5 font-sans text-[0.8125rem] font-medium text-ink-subtle">
                {group.title}
                {group.count !== null && <span className="tabular-nums"> · {formatNumber(group.count)}</span>}
            </h2>

            {group.chapters.length > 0 && (
                <ul className="mt-2 flex flex-col gap-0.5">
                    {group.chapters.map((chapter) => (
                        <ChapterItem
                            key={chapter.href}
                            href={chapter.href}
                            label={chapter.label}
                            count={chapter.count}
                            isActive={chapter.href === activeHref}
                            activeItemRef={activeItemRef}
                        />
                    ))}
                </ul>
            )}

            {group.folders.length > 0 && <ModuleTree folders={group.folders} activeHref={activeHref} activeItemRef={activeItemRef} />}
        </section>
    );
}

type ChapterItemProps = {
    href: string;
    label: string;
    count: number | null;
    /** Ce citește un cititor de ecran în locul numărului, de exemplu „9 fișiere”. */
    countText?: string;
    isActive: boolean;
    activeItemRef: Ref<HTMLLIElement>;
    /**
     * Unde stă în cuprins: „chapter” = capitol obișnuit; „module” = modul singur în folderul lui, aliniat cu
     * numele folderelor (după locul săgeții); „nested” = modul dintr-un folder deschis, cu literă puțin mai mică.
     */
    variant?: 'chapter' | 'module' | 'nested';
};

const variantClasses: Record<NonNullable<ChapterItemProps['variant']>, string> = {
    chapter: '',
    module: 'pl-8',
    nested: 'text-[0.8125rem]',
};

function ChapterItem({ href, label, count, countText, isActive, activeItemRef, variant = 'chapter' }: ChapterItemProps) {
    return (
        <li ref={isActive ? activeItemRef : undefined}>
            <ChapterLink
                href={href}
                aria-current={isActive ? 'page' : undefined}
                className={`${itemClasses} ${variantClasses[variant]} ${isActive ? activeItemClasses : idleItemClasses}`}
            >
                <span className="min-w-0 flex-1 truncate" title={label}>
                    {label}
                </span>
                {count !== null && (
                    <>
                        <span aria-hidden="true" className="shrink-0 text-xs text-ink-subtle tabular-nums">
                            {formatNumber(count)}
                        </span>
                        <span className="sr-only">, {countText ?? formatNumber(count)}</span>
                    </>
                )}
            </ChapterLink>
        </li>
    );
}

type ModuleTreeProps = {
    folders: WorkspaceModuleFolder[];
    activeHref: string | null;
    activeItemRef: Ref<HTMLLIElement>;
};

/**
 * Modulele, grupate pe folderul de sus („app” → „Http”, „Models”). Un folder cu un singur modul apare
 * direct, fără să trebuiască deschis. La început e deschis folderul capitolului curent sau, dacă niciun
 * modul nu e deschis, cel mai mare folder. Above FILTER_ABOVE_MODULE_COUNT modules a filter field appears.
 */
function ModuleTree({ folders, activeHref, activeItemRef }: ModuleTreeProps) {
    const { t, plural } = useI18n();
    const filterId = useId();
    const [query, setQuery] = useState('');

    const modules = folders.flatMap((folder) => folder.modules);
    const activeFolder = folders.find((folder) => folder.modules.some((module) => module.href === activeHref))?.name ?? null;
    const biggestFolder = folders.reduce<WorkspaceModuleFolder | null>(
        (biggest, folder) => (folder.modules.length > 1 && (biggest === null || folder.file_count > biggest.file_count) ? folder : biggest),
        null,
    );

    const [openFolders, setOpenFolders] = useState<string[]>(() => [activeFolder ?? biggestFolder?.name ?? ''].filter(Boolean));

    // Un capitol deschis dintr-un folder închis (de exemplu din alt loc al paginii) își deschide folderul.
    // E felul recomandat de React de a ajusta starea când se schimbă o valoare primită: direct în randare.
    const [shownActiveFolder, setShownActiveFolder] = useState(activeFolder);

    if (activeFolder !== shownActiveFolder) {
        setShownActiveFolder(activeFolder);

        if (activeFolder !== null && !openFolders.includes(activeFolder)) {
            setOpenFolders([...openFolders, activeFolder]);
        }
    }

    function toggleFolder(name: string) {
        setOpenFolders(openFolders.includes(name) ? openFolders.filter((open) => open !== name) : [...openFolders, name]);
    }

    const normalizedQuery = query.trim().toLowerCase();
    const matchingModules = normalizedQuery === '' ? [] : modules.filter((module) => module.path.toLowerCase().includes(normalizedQuery));

    const moduleItem = (module: WorkspaceModule, label: string, variant: 'module' | 'nested') => (
        <ChapterItem
            key={module.id}
            href={module.href}
            label={label}
            count={module.file_count}
            countText={plural('files', module.file_count)}
            isActive={module.href === activeHref}
            activeItemRef={activeItemRef}
            variant={variant}
        />
    );

    return (
        <div className="mt-2">
            {modules.length > FILTER_ABOVE_MODULE_COUNT && (
                <div className="mb-2">
                    <label htmlFor={filterId} className="sr-only">
                        {t('workspace.filterModulesByPath')}
                    </label>
                    <div className="relative">
                        <SearchIcon className="pointer-events-none absolute top-1/2 left-2.5 size-3.5 -translate-y-1/2 text-ink-subtle" />
                        <input
                            id={filterId}
                            type="search"
                            value={query}
                            onChange={(event) => setQuery(event.target.value)}
                            placeholder={t('workspace.filterModules')}
                            autoComplete="off"
                            className="w-full rounded-lg border border-line-strong bg-canvas py-1.5 pr-2.5 pl-8 text-sm text-ink placeholder:text-ink-subtle focus:outline-2 focus:-outline-offset-1 focus:outline-accent"
                        />
                    </div>
                    {/* Zona aria-live există de la început, ca cititoarele de ecran să anunțe câte module s-au găsit. */}
                    <p aria-live="polite" className="px-2.5 pt-2 text-xs text-ink-subtle empty:hidden">
                        {normalizedQuery === ''
                            ? ''
                            : matchingModules.length === 0
                              ? t('workspace.noModuleMatches')
                              : plural('modulesFound', matchingModules.length)}
                    </p>
                </div>
            )}

            {normalizedQuery !== '' ? (
                <ul className="flex flex-col gap-0.5">{matchingModules.map((module) => moduleItem(module, moduleLabel(module, t('workspace.root')), 'module'))}</ul>
            ) : (
                <ul className="flex flex-col gap-0.5">
                    {folders.map((folder) =>
                        folder.modules.length === 1 ? (
                            moduleItem(folder.modules[0], moduleLabel(folder.modules[0], t('workspace.root')), 'module')
                        ) : (
                            <ModuleFolder
                                key={folder.name}
                                folder={folder}
                                isOpen={openFolders.includes(folder.name)}
                                onToggle={() => toggleFolder(folder.name)}
                            >
                                {folder.modules.map((module) => moduleItem(module, module.label, 'nested'))}
                            </ModuleFolder>
                        ),
                    )}
                </ul>
            )}
        </div>
    );
}

/** Numele unui modul afișat singur (fără folderul lui deasupra): toată calea, iar „/” devine „rădăcină”. */
function moduleLabel(module: WorkspaceModule, rootLabel: string): string {
    return module.path === '/' ? rootLabel : module.path;
}

type ModuleFolderProps = {
    folder: WorkspaceModuleFolder;
    isOpen: boolean;
    onToggle: () => void;
    children: ReactNode;
};

/** Un folder din cuprins: butonul lui deschide sau închide lista de module (aria-expanded). */
function ModuleFolder({ folder, isOpen, onToggle, children }: ModuleFolderProps) {
    const { t, plural } = useI18n();
    const listId = useId();

    return (
        <li>
            <button
                type="button"
                onClick={onToggle}
                aria-expanded={isOpen}
                aria-controls={listId}
                className={`${itemClasses} cursor-pointer font-medium text-ink hover:bg-surface-2`}
            >
                <ChevronIcon className={`size-3.5 shrink-0 text-ink-subtle motion-safe:transition-transform ${isOpen ? 'rotate-90' : ''}`} />
                <span className="min-w-0 flex-1 truncate">{folder.name === '/' ? t('workspace.root') : folder.name}</span>
                <span aria-hidden="true" className="shrink-0 text-xs font-normal text-ink-subtle tabular-nums">
                    {formatNumber(folder.file_count)}
                </span>
                <span className="sr-only">, {plural('files', folder.file_count)}</span>
            </button>

            {/* O linie verticală sub săgeată leagă modulele de folderul lor, ca într-un explorator de fișiere. */}
            <ul id={listId} hidden={!isOpen} className="mt-0.5 ml-4 flex flex-col gap-0.5 border-l border-line pl-2">
                {children}
            </ul>
        </li>
    );
}
