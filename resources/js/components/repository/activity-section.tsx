import { usePage } from '@inertiajs/react';
import ActivityCard from '@/components/timeline/activity-card';
import UnboxedSection from '@/components/repository/unboxed-section';
import type { RepositoryPageProps } from '@/types';
import type { Activity } from '@/types/history';

type Props = {
    /** Commit-urile ultimelor 12 luni și contribuitorii, din istoricul git importat; `null` până la primul import. */
    activity: Activity | null;
};

/**
 * „Activitate” pe pagina Prezentare: cât s-a lucrat la proiect în fiecare lună, din istoricul git,
 * cu legătură spre tab-ul „Istoric”. Fără date secțiunea nu apare deloc: nu afișăm nimic inventat.
 */
export default function ActivitySection({ activity }: Props) {
    const { repository } = usePage<RepositoryPageProps>().props;

    if (!activity) {
        return null;
    }

    return (
        <UnboxedSection>
            <ActivityCard activity={activity} timelineHref={`/repositories/${repository.id}/timeline`} />
        </UnboxedSection>
    );
}
