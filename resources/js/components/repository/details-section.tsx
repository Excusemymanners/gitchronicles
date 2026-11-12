import SectionCard from '@/components/repository/section-card';
import { useI18n } from '@/hooks/use-i18n';
import { formatDate } from '@/lib/format';
import type { RepositoryItem } from '@/types';

type Props = {
    repository: RepositoryItem;
};

/**
 * „Detalii” pe pagina Prezentare: când a fost adăugat repository-ul și când s-a terminat fiecare
 * etapă a procesării. O etapă care nu s-a terminat încă nu apare în listă.
 */
export default function DetailsSection({ repository }: Props) {
    const { t } = useI18n();

    const dates = [
        { label: t('overview.details.added'), date: repository.created_at },
        { label: t('overview.details.cloned'), date: repository.cloned_at },
        { label: t('overview.details.analyzed'), date: repository.analyzed_at },
        { label: t('overview.details.documented'), date: repository.documented_at },
    ].filter((item): item is { label: string; date: string } => item.date !== null);

    return (
        <SectionCard title={t('overview.details.title')}>
            <dl className="grid gap-6 sm:grid-cols-2">
                {dates.map((item) => (
                    <div key={item.label}>
                        <dt className="text-sm text-ink-subtle">{item.label}</dt>
                        <dd className="mt-1 text-sm text-ink">
                            <time dateTime={item.date}>{formatDate(item.date)}</time>
                        </dd>
                    </div>
                ))}
            </dl>
        </SectionCard>
    );
}
