import { RegenerateOffer } from '@/components/repository/chapter-empty-state';
import { useI18n } from '@/hooks/use-i18n';
import { formatList } from '@/lib/format';
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
    const { t } = useI18n();

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
                    {t('overview.missing.title', { items: formatList(completeness.missing.map((key) => t(`overview.missing.artifacts.${key}`))) })}
                </h2>
                <p className="mt-1 leading-relaxed">{completeness.heals_automatically ? t('overview.missing.textHeals') : t('overview.missing.text')}</p>
            </div>

            <RegenerateOffer className="shrink-0" />
        </section>
    );
}
