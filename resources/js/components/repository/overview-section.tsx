import { Link } from '@inertiajs/react';
import { ArrowRightIcon } from '@/components/icons';
import Markdown from '@/components/docs/markdown';
import SectionCard from '@/components/repository/section-card';
import { useI18n } from '@/hooks/use-i18n';
import type { OverviewSummary } from '@/types/docs';

type Props = {
    /** The first sections of the generated overview page; `null` until it is written. */
    overview: OverviewSummary | null;
    /** The paragraph the hero already shows; it is left out here so the page does not say it twice. */
    shownParagraph?: string | null;
};

/**
 * "About the project" on the Overview: what the project does and which technologies it uses, from the generated
 * overview page, with a link to the whole documentation. Without data the section is left out: nothing invented.
 */
export default function OverviewSection({ overview, shownParagraph = null }: Props) {
    const { t } = useI18n();

    if (!overview) {
        return null;
    }

    const markdown = shownParagraph ? overview.markdown.replace(shownParagraph, '').trim() : overview.markdown;

    return (
        <SectionCard
            title={t('overview.about')}
            aside={
                <Link
                    href={overview.url}
                    className="inline-flex items-center gap-1.5 rounded-sm text-sm font-medium text-accent hover:text-accent-hover hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
                >
                    {t('overview.allDocs')}
                    <ArrowRightIcon className="size-3.5" />
                </Link>
            }
        >
            <div className="max-w-190">
                <Markdown>{markdown}</Markdown>
            </div>
        </SectionCard>
    );
}
