import { Link } from '@inertiajs/react';
import MermaidDiagram from '@/components/diagrams/mermaid-diagram';
import { useI18n } from '@/hooks/use-i18n';
import type { Diagram } from '@/components/diagrams/types';

type Props = {
    diagram: Diagram;
    /** Unde duce cardul: de obicei tab-ul „Diagrame”, la secțiunea diagramei. */
    href: string;
    /** Textul legăturii de sub previzualizare. */
    linkLabel?: string;
    /**
     * „sm”: într-un panou lateral, previzualizarea sub titlu;
     * „md”: pe o pagină, textul în stânga și o previzualizare mai mare în dreapta (când cardul e destul de lat).
     */
    size?: 'sm' | 'md';
};

/**
 * Un card cu previzualizarea unei diagrame (micșorată ca să încapă toată), titlul și propoziția ei.
 * Tot cardul e o legătură spre vederea completă, ca să poată sta și într-un panou lateral.
 */
export default function DiagramCard({ diagram, href, linkLabel, size = 'sm' }: Props) {
    const { t } = useI18n();
    const preview = (
        <div className={`overflow-hidden rounded-md border border-line-subtle bg-canvas p-2 ${size === 'sm' ? 'h-40' : 'h-80'}`}>
            <MermaidDiagram code={diagram.mermaid} title={diagram.title} compact />
        </div>
    );

    return (
        <Link
            href={href}
            className="group @container block rounded-lg border border-line bg-surface p-3 shadow-xs hover:border-line-strong hover:bg-surface-2 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent sm:p-4"
        >
            <div className={size === 'md' ? 'grid grid-cols-[minmax(0,1fr)] gap-4 @xl:grid-cols-[minmax(0,1fr)_minmax(0,1.4fr)] @xl:items-center' : 'flex flex-col gap-2'}>
                <div className="flex flex-col gap-2">
                    <p className="text-sm font-medium text-ink">{diagram.title}</p>
                    {size === 'sm' && preview}
                    {diagram.caption && <p className={`text-ink-subtle ${size === 'sm' ? 'line-clamp-2 text-xs' : 'text-sm'}`}>{diagram.caption}</p>}
                    <p className="text-sm font-medium text-accent group-hover:text-accent-hover">
                        {linkLabel ?? t('diagrams.openDiagrams')} <span aria-hidden="true">→</span>
                    </p>
                </div>
                {size === 'md' && preview}
            </div>
        </Link>
    );
}
