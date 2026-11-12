import CompactCard from '@/components/timeline/compact-card';
import HotspotList from '@/components/timeline/hotspot-list';
import { useI18n } from '@/hooks/use-i18n';
import type { RepositoryItem } from '@/types';
import type { Hotspot } from '@/types/history';

type Props = {
    repository: RepositoryItem;
    /** Primele fișiere din „Zone fierbinți” (pe Prezentare: primele 5). */
    hotspots: Hotspot[];
    /** Legătura spre lista completă din tab-ul „Istoric”; fără ea cardul nu are legătură. */
    timelineHref?: string;
};

/** Cardul compact „Zone fierbinți”: fișierele schimbate cel mai des, fiecare cu numărul de commit-uri. */
export default function HotspotsCard({ repository, hotspots, timelineHref }: Props) {
    const { t } = useI18n();

    return (
        <CompactCard title={t('timeline.hotspotsCard.title')} link={timelineHref ? { href: timelineHref, label: t('timeline.hotspotsCard.viewAll') } : undefined}>
            <p className="mb-4 text-sm text-ink-muted">{t('timeline.hotspotsExplanation')}</p>
            <HotspotList repository={repository} hotspots={hotspots} compact />
        </CompactCard>
    );
}
