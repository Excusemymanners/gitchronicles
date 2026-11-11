import { formatPercent } from '@/lib/format';
import type { LanguageShare } from '@/types/home';

type Props = {
    /** Primele limbaje după numărul de linii, cu partea fiecăruia din total (între 0 și 1). */
    languages: LanguageShare[];
};

/**
 * Culoarea fiecărui segment: aceeași nuanță de accent, tot mai stinsă. Nu colorăm după limbaj
 * (ar cere o paletă întreagă în afara tokenilor); ordinea și legenda spun care e care.
 */
const segmentClasses = ['bg-accent', 'bg-accent/60', 'bg-accent/30'];

/**
 * Limbajele unui repository: o bară împărțită după numărul de linii și, sub ea, legenda.
 * Ce rămâne din bară (alte limbaje și fișiere cu limbaj necunoscut) e „Altele”.
 */
export default function LanguageBar({ languages }: Props) {
    const namedShare = languages.reduce((sum, language) => sum + language.share, 0);
    const otherShare = Math.max(0, 1 - namedShare);

    return (
        <div>
            {/* Bara e decorativă: aceleași valori sunt scrise în legenda de sub ea. */}
            <div aria-hidden="true" className="flex h-1.5 gap-0.5 overflow-hidden rounded-full">
                {languages.map((language, index) => (
                    <span key={language.name} style={{ width: `${language.share * 100}%` }} className={`rounded-full ${segmentClasses[index]}`} />
                ))}
                {otherShare > 0.005 && <span className="flex-1 rounded-full bg-line-strong" />}
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
                        <span aria-hidden="true" className="size-2 rounded-full bg-line-strong" />
                        <span>Altele</span>
                        <span className="tabular-nums">{formatPercent(otherShare)}</span>
                    </li>
                )}
            </ul>
        </div>
    );
}
