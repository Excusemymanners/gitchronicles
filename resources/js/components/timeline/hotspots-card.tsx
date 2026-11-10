import CompactCard from '@/components/timeline/compact-card';
import HotspotList from '@/components/timeline/hotspot-list';
import type { RepositoryItem } from '@/types';
import type { Hotspot } from '@/types/history';

type Props = {
    repository: RepositoryItem;
    /** Primele fișiere din „Zone fierbinți” (pe Prezentare: primele 5). */
    hotspots: Hotspot[];
    /** Legătura spre lista completă din tab-ul „Istoric”; fără ea cardul nu are legătură. */
    timelineHref?: string;
};

/** Explicația scurtă a „Zonelor fierbinți”, aceeași pe card și în tab-ul „Istoric”. */
export const HOTSPOTS_EXPLANATION = 'Fișierele schimbate cel mai des sunt de obicei cele mai riscante și cele mai importante de înțeles.';

/** Cardul compact „Zone fierbinți”: fișierele schimbate cel mai des, fiecare cu numărul de commit-uri. */
export default function HotspotsCard({ repository, hotspots, timelineHref }: Props) {
    return (
        <CompactCard title="Zone fierbinți" link={timelineHref ? { href: timelineHref, label: 'Vezi toate' } : undefined}>
            <p className="mb-4 text-sm text-ink-muted">{HOTSPOTS_EXPLANATION}</p>
            <HotspotList repository={repository} hotspots={hotspots} compact />
        </CompactCard>
    );
}
