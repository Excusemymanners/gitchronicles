import { countLabel, formatNumber, formatPercent } from '@/lib/format';
import type { CodeStructure } from '@/types';

type Props = {
    codeStructure: CodeStructure;
};

const cardClasses = 'rounded-lg border border-line bg-surface p-4 shadow-xs sm:p-6';

// Pe telefon: numele și bara pe un rând, cifrele dedesubt; de la „sm” în sus: toate trei pe un rând.
const languageRowClasses = 'grid grid-cols-[7rem_1fr] items-center gap-x-4 gap-y-1 text-sm sm:grid-cols-[9rem_1fr_11rem]';

/**
 * Secțiunea „Structura codului”: cifrele principale, limbajele (cu o bară pentru partea din linii),
 * modulele și cele mai puternice dependențe dintre module, așa cum le-a găsit analiza codului.
 */
export default function CodeStructureSection({ codeStructure }: Props) {
    const { languages, modules, dependencies } = codeStructure;

    const keyNumbers = [
        { label: 'Fișiere', value: codeStructure.file_count },
        { label: 'Linii de cod', value: codeStructure.line_count },
        { label: 'Module', value: codeStructure.module_count },
        { label: 'Limbaje', value: codeStructure.language_count },
    ];

    return (
        <div className="mt-4 flex flex-col gap-6">
            <dl className="grid grid-cols-2 gap-4 sm:grid-cols-4">
                {keyNumbers.map(({ label, value }) => (
                    <div key={label} className="rounded-lg border border-line bg-surface px-4 py-3 shadow-xs">
                        <dt className="text-sm text-ink-subtle">{label}</dt>
                        <dd className="mt-1 text-2xl font-semibold tracking-tight text-ink tabular-nums">{formatNumber(value)}</dd>
                    </div>
                ))}
            </dl>

            <div className={cardClasses}>
                <h3 className="font-medium text-ink">Limbaje</h3>
                <ul className="mt-4 flex flex-col gap-3">
                    {languages.map((language) => {
                        // Partea limbajului din totalul liniilor (între 0 și 1); bara și textul arată același număr.
                        const share = codeStructure.line_count > 0 ? language.line_count / codeStructure.line_count : 0;

                        return (
                            <li key={language.name} className={languageRowClasses}>
                                <span className="truncate font-medium text-ink">{language.name}</span>
                                <span aria-hidden="true" className="h-2 rounded-full bg-surface-2">
                                    <span className="block h-2 rounded-full bg-accent" style={{ width: `${Math.max(share * 100, 1)}%` }} />
                                </span>
                                <span className="col-start-2 text-ink-subtle tabular-nums sm:col-start-auto sm:text-right">
                                    {countLabel(language.line_count, 'linie', 'linii')} · {formatPercent(share)}
                                </span>
                            </li>
                        );
                    })}
                </ul>
            </div>

            <div className={cardClasses}>
                <h3 className="font-medium text-ink">Module</h3>
                <p className="mt-1 text-sm text-ink-subtle">Fișierele sunt grupate după folder; „/” este folderul principal al proiectului.</p>
                {/* Un proiect mare poate avea sute de module: lista se derulează în casetă, cu antetul fix. */}
                <div className="mt-4 max-h-112 overflow-auto">
                    <table className="w-full text-left text-sm">
                        <thead className="sticky top-0 border-b border-line bg-surface text-ink-subtle">
                            <tr>
                                <th scope="col" className="py-2 pr-4 font-medium">
                                    Modul
                                </th>
                                <th scope="col" className="py-2 pr-4 text-right font-medium">
                                    Fișiere
                                </th>
                                <th scope="col" className="py-2 text-right font-medium">
                                    Linii
                                </th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-line-subtle">
                            {/*
                              Fiecare rând are o ancoră (#modul-{id}): cuprinsul duce aici modulele fără pagină de documentație.
                              scroll-mt lasă loc antetului fix al tabelului; rândul deschis din cuprins e evidențiat (:target).
                            */}
                            {modules.map((module) => (
                                <tr key={module.id} id={`modul-${module.id}`} className="scroll-mt-10 target:bg-accent-soft">
                                    <td className="py-2 pr-4">
                                        <code className="font-mono break-all text-ink">{module.path}</code>
                                    </td>
                                    <td className="py-2 pr-4 text-right text-ink-muted tabular-nums">{formatNumber(module.file_count)}</td>
                                    <td className="py-2 text-right text-ink-muted tabular-nums">{formatNumber(module.line_count)}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>

            <div className={cardClasses}>
                <h3 className="font-medium text-ink">Dependențe între module</h3>
                {dependencies.length === 0 ? (
                    <p className="mt-1 text-sm text-ink-subtle">Modulele nu se importă între ele.</p>
                ) : (
                    <>
                        <p className="mt-1 text-sm text-ink-subtle">
                            {dependencies.length < codeStructure.dependency_count &&
                                `Cele mai puternice ${dependencies.length} din ${codeStructure.dependency_count}. `}
                            Modulul din stânga importă fișiere din cel din dreapta.
                        </p>
                        <ul className="mt-4 divide-y divide-line-subtle text-sm">
                            {dependencies.map((dependency) => (
                                <li key={`${dependency.from}→${dependency.to}`} className="flex flex-wrap items-center justify-between gap-x-4 gap-y-1 py-2">
                                    <span className="flex min-w-0 flex-wrap items-center gap-2">
                                        <code className="font-mono break-all text-ink">{dependency.from}</code>
                                        <span aria-hidden="true" className="text-base text-ink-subtle">
                                            →
                                        </span>
                                        <span className="sr-only">importă din</span>
                                        <code className="font-mono break-all text-ink">{dependency.to}</code>
                                    </span>
                                    <span className="text-ink-subtle tabular-nums">
                                        {countLabel(dependency.weight, 'import', 'importuri')}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    </>
                )}
            </div>
        </div>
    );
}
