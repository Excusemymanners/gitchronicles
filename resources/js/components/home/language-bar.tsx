import { useI18n } from '@/hooks/use-i18n';
import { formatPercent } from '@/lib/format';
import type { LanguageShare } from '@/types/home';

type Props = {
    /** Primele limbaje după numărul de linii, cu partea fiecăruia din total (între 0 și 1). */
    languages: LanguageShare[];
};

/**
 * The colour of each segment: the ink, ever fainter (Brand 3 keeps the accent for links, focus and selection).
 * We do not colour by language (that would need a whole palette outside the tokens); the order and the legend
 * say which is which.
 */
const segmentClasses = ['bg-ink/85', 'bg-ink/50', 'bg-ink/25'];

/**
 * Limbajele unui repository: o bară împărțită după numărul de linii și, sub ea, legenda.
 * Ce rămâne din bară (alte limbaje și fișiere cu limbaj necunoscut) e „Altele”.
 */
export default function LanguageBar({ languages }: Props) {
    const namedShare = languages.reduce((sum, language) => sum + language.share, 0);
    const otherShare = Math.max(0, 1 - namedShare);
    const { t } = useI18n();

    return (
        <div>
            {/* Bara e decorativă: aceleași valori sunt scrise în legenda de sub ea. */}
            <div aria-hidden="true" className="flex h-1.5 gap-0.5 overflow-hidden rounded-full">
                {languages.map((language, index) => (
                    <span key={language.name} style={{ width: `${language.share * 100}%` }} className={`rounded-full ${segmentClasses[index]}`} />
                ))}
                {otherShare > 0.005 && <span className="flex-1 rounded-full bg-line" />}
            </div>

            <ul className="mt-2.5 flex flex-wrap gap-x-4 gap-y-1 text-[0.8125rem] text-ink-muted">
                {languages.map((language, index) => (
                    <li key={language.name} className="flex items-center gap-1.5">
                        <span aria-hidden="true" className={`size-2 rounded-full ${segmentClasses[index]}`} />
                        <span className="text-ink">{language.name}</span>
                        <span className="tabular-nums">{formatPercent(language.share)}</span>
                    </li>
                ))}
                {otherShare > 0.005 && (
                    <li className="flex items-center gap-1.5">
                        <span aria-hidden="true" className="size-2 rounded-full bg-line" />
                        <span>{t('common.other')}</span>
                        <span className="tabular-nums">{formatPercent(otherShare)}</span>
                    </li>
                )}
            </ul>
        </div>
    );
}
