import { RegenerateOffer } from '@/components/repository/chapter-empty-state';
import type { Completeness, RepositoryItem } from '@/types';

type Props = {
    repository: RepositoryItem;
    completeness: Completeness;
};

/**
 * Nota de pe „Prezentare generală” pentru un repository gata căruia îi lipsesc capitole (de exemplu unul
 * procesat de o versiune mai veche a GitChronicle): ce lipsește, ce le face și butonul „Regenerează tot”.
 * Video-ul și slide-urile nu sunt aici: au coada și pagina lor.
 */
export default function MissingArtifactsNotice({ repository, completeness }: Props) {
    if (repository.status !== 'ready' || !completeness.is_incomplete) {
        return null;
    }

    return (
        <section
            aria-labelledby="missing-artifacts-heading"
            className="mt-6 flex flex-col gap-3 rounded-lg border border-info-line bg-info-soft px-4 py-3 text-sm text-info-ink sm:flex-row sm:items-center sm:justify-between"
        >
            <div>
                <h2 id="missing-artifacts-heading" className="font-sans text-sm font-semibold">
                    Lipsesc {completeness.missing_text}.
                </h2>
                <p className="mt-1 leading-relaxed">
                    Repository-ul a fost procesat fără ele, de exemplu de o versiune mai veche a GitChronicle. O procesare completă le face
                    {completeness.heals_automatically ? ': pornește singură la următoarea verificare a actualizărilor sau acum, cu „Regenerează tot”.' : '.'}
                </p>
            </div>

            <RegenerateOffer className="shrink-0" />
        </section>
    );
}
