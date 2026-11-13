import { Link } from '@inertiajs/react';
import { useEffect, useRef } from 'react';
import type { Section, SectionKey } from '@/components/workspace/sections';
import { useI18n } from '@/hooks/use-i18n';
import { revealInScrollParent } from '@/lib/workspace';

type Props = {
    sections: Section[];
    activeKey: SectionKey | null;
};

/**
 * The row of section tabs under the top bar: Overview, Documentation, Diagrams, Ask, History, Video & slides,
 * Report. The open section has a 2 px accent underline that draws itself in (animate-grow-x, from the left).
 * On a narrow screen the row scrolls sideways, and the open tab is brought into view.
 */
export default function SectionTabs({ sections, activeKey }: Props) {
    const { t } = useI18n();
    const activeRef = useRef<HTMLAnchorElement>(null);

    useEffect(() => {
        revealInScrollParent(activeRef.current, 'x');
    }, [activeKey]);

    return (
        <nav aria-label={t('workspace.sections.label')} className="border-b border-line bg-surface">
            <ul className="flex gap-1 overflow-x-auto px-2 [scrollbar-width:none] sm:px-4">
                {sections.map((section) => {
                    const isActive = section.key === activeKey;

                    return (
                        <li key={section.key} className="shrink-0">
                            <Link
                                ref={isActive ? activeRef : undefined}
                                href={section.href}
                                prefetch
                                aria-current={isActive ? 'page' : undefined}
                                className={`relative flex h-11 items-center rounded-md px-3 text-sm whitespace-nowrap focus-visible:outline-2 focus-visible:-outline-offset-4 focus-visible:outline-accent ${
                                    isActive ? 'font-medium text-ink' : 'text-ink-muted hover:text-ink'
                                }`}
                            >
                                {section.label}
                                {isActive && (
                                    <span
                                        aria-hidden="true"
                                        className="absolute inset-x-3 bottom-0 h-0.5 origin-left rounded-full bg-accent motion-safe:animate-grow-x"
                                    />
                                )}
                            </Link>
                        </li>
                    );
                })}
            </ul>
        </nav>
    );
}
