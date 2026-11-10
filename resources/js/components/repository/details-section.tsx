import SectionCard from '@/components/repository/section-card';
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
    const dates = [
        { label: 'Adăugat la', date: repository.created_at },
        { label: 'Clonat la', date: repository.cloned_at },
        { label: 'Cod analizat la', date: repository.analyzed_at },
        { label: 'Documentație scrisă la', date: repository.documented_at },
    ].filter((item): item is { label: string; date: string } => item.date !== null);

    return (
        <SectionCard title="Detalii">
            <dl className="grid gap-6 sm:grid-cols-2">
                {dates.map((item) => (
                    <div key={item.label}>
                        <dt className="text-sm text-gray-500">{item.label}</dt>
                        <dd className="mt-1 text-sm text-gray-900">
                            <time dateTime={item.date}>{formatDate(item.date)}</time>
                        </dd>
                    </div>
                ))}
            </dl>
        </SectionCard>
    );
}
