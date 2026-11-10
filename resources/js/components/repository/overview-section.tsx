import { Link } from '@inertiajs/react';
import Markdown from '@/components/docs/markdown';
import SectionCard from '@/components/repository/section-card';
import type { OverviewSummary } from '@/types/docs';

type Props = {
    /** Primele secțiuni ale prezentării generale din documentația generată; `null` până e scrisă. */
    overview: OverviewSummary | null;
};

/**
 * „Despre proiect” pe pagina Prezentare: ce face proiectul și ce tehnologii folosește, din prezentarea
 * generală a documentației, plus legătura spre toată documentația.
 * Fără date secțiunea nu apare deloc: nu afișăm nimic inventat.
 */
export default function OverviewSection({ overview }: Props) {
    if (!overview) {
        return null;
    }

    return (
        <SectionCard title="Despre proiect">
            <Markdown>{overview.markdown}</Markdown>

            <p className="mt-6">
                <Link
                    href={overview.url}
                    className="rounded-sm text-sm font-medium text-accent hover:text-accent-hover hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
                >
                    Toată documentația <span aria-hidden="true">→</span>
                </Link>
            </p>
        </SectionCard>
    );
}
