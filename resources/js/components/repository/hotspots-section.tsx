import { usePage } from '@inertiajs/react';
import HotspotsCard from '@/components/timeline/hotspots-card';
import UnboxedSection from '@/components/repository/unboxed-section';
import type { RepositoryPageProps } from '@/types';
import type { Hotspot } from '@/types/history';

type Props = {
    /** Primele 5 fișiere schimbate cel mai des, din istoricul git importat; `null` când nu există. */
    hotspots: Hotspot[] | null;
};

/**
 * „Zone fierbinți” pe pagina Prezentare: fișierele schimbate cel mai des (acolo apar de obicei bug-urile
 * și pe ele trebuie să le înțeleagă primele un nou venit), cu legătură spre lista completă din „Istoric”.
 * Fără date secțiunea nu apare deloc: nu afișăm nimic inventat.
 */
export default function HotspotsSection({ hotspots }: Props) {
    const { repository } = usePage<RepositoryPageProps>().props;

    if (!hotspots) {
        return null;
    }

    return (
        <UnboxedSection>
            <HotspotsCard repository={repository} hotspots={hotspots} timelineHref={`/repositories/${repository.id}/timeline#zone-fierbinti`} />
        </UnboxedSection>
    );
}
