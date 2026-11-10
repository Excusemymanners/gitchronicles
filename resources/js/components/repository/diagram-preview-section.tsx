import { usePage } from '@inertiajs/react';
import DiagramCard from '@/components/diagrams/diagram-card';
import type { Diagram } from '@/components/diagrams/types';
import type { RepositoryPageProps } from '@/types';

type Props = {
    /** Diagrama pe straturi a repository-ului (Faza 7); `null` până o desenează BuildDiagrams. */
    preview: Diagram | null;
};

/**
 * Previzualizarea arhitecturii pe pagina Prezentare: diagrama pe straturi, micșorată,
 * cu legătură spre tab-ul „Diagrame”. Fără diagramă secțiunea nu apare deloc: nu afișăm nimic inventat.
 */
export default function DiagramPreviewSection({ preview }: Props) {
    const { repository } = usePage<RepositoryPageProps>().props;

    if (!preview) {
        return null;
    }

    return (
        <section aria-labelledby="diagram-preview-heading">
            <h2 id="diagram-preview-heading" className="text-lg font-semibold tracking-tight">
                Arhitectura
            </h2>
            <div className="mt-4">
                <DiagramCard diagram={preview} href={`/repositories/${repository.id}/diagrams#straturi`} linkLabel="Vezi toate diagramele" size="md" />
            </div>
        </section>
    );
}
