import { useI18n } from '@/hooks/use-i18n';
import { formatDate } from '@/lib/format';
import type { ConsultingReport } from '@/types/consulting';

type Props = {
    freshness: ConsultingReport['freshness'];
    finishedAt: string | null;
};

/**
 * „Cât de actual este”: o linie cu data fiecărei surse de date a raportului — când a fost făcut, când au fost
 * reînnoite regulile Semgrep (și din ce revizii), când a fost întrebată baza de date OSV și când au fost aduse datele
 * de suport ale versiunilor. O sursă care n-a rulat nu apare.
 */
export default function FreshnessLine({ freshness, finishedAt }: Props) {
    const { t } = useI18n();
    const parts: string[] = [];

    if (finishedAt) {
        parts.push(t('consulting.freshness.made', { date: formatDate(finishedAt) }));
    }

    if (freshness.has_rules) {
        parts.push(
            freshness.rules === null
                ? t('consulting.freshness.rulesNever')
                : t('consulting.freshness.rules', { date: formatDate(freshness.rules.updated_at), revision: freshness.rules.revision }),
        );
    }

    if (freshness.has_osv && freshness.osv_queried_at) {
        parts.push(t('consulting.freshness.osv', { date: formatDate(freshness.osv_queried_at) }));
    }

    if (freshness.has_runtime && freshness.runtime_fetched_at) {
        parts.push(t('consulting.freshness.runtime', { date: formatDate(freshness.runtime_fetched_at) }));
    }

    return (
        <p className="text-[0.8125rem] text-ink-subtle" data-freshness>
            <span className="font-medium text-ink-muted">{t('consulting.freshness.title')}:</span> {parts.join(' · ')}
        </p>
    );
}
