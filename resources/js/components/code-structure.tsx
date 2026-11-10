import { countLabel, formatNumber, formatPercent } from '@/lib/format';
import type { CodeStructure } from '@/types';

type Props = {
    codeStructure: CodeStructure;
};

const cardClasses = 'rounded-lg border border-gray-200 bg-white p-4 shadow-xs sm:p-6';

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
                    <div key={label} className="rounded-lg border border-gray-200 bg-white px-4 py-3 shadow-xs">
                        <dt className="text-sm text-gray-500">{label}</dt>
                        <dd className="mt-1 text-2xl font-semibold tracking-tight text-gray-900 tabular-nums">{formatNumber(value)}</dd>
                    </div>
                ))}
            </dl>

            <div className={cardClasses}>
                <h3 className="font-medium text-gray-900">Limbaje</h3>
                <ul className="mt-4 flex flex-col gap-3">
                    {languages.map((language) => {
                        // Partea limbajului din totalul liniilor (între 0 și 1); bara și textul arată același număr.
                        const share = codeStructure.line_count > 0 ? language.line_count / codeStructure.line_count : 0;

                        return (
                            <li key={language.name} className={languageRowClasses}>
                                <span className="truncate font-medium text-gray-900">{language.name}</span>
                                <span aria-hidden="true" className="h-2 rounded-full bg-gray-100">
                                    <span className="block h-2 rounded-full bg-indigo-500" style={{ width: `${Math.max(share * 100, 1)}%` }} />
                                </span>
                                <span className="col-start-2 text-gray-500 tabular-nums sm:col-start-auto sm:text-right">
                                    {countLabel(language.line_count, 'linie', 'linii')} · {formatPercent(share)}
                                </span>
                            </li>
                        );
                    })}
                </ul>
            </div>

            <div className={cardClasses}>
                <h3 className="font-medium text-gray-900">Module</h3>
                <p className="mt-1 text-sm text-gray-500">Fișierele sunt grupate după folder; „/” este folderul principal al proiectului.</p>
                {/* Un proiect mare poate avea sute de module: lista se derulează în casetă, cu antetul fix. */}
                <div className="mt-4 max-h-112 overflow-auto">
                    <table className="w-full text-left text-sm">
                        <thead className="sticky top-0 border-b border-gray-200 bg-white text-gray-500">
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
                        <tbody className="divide-y divide-gray-100">
                            {modules.map((module) => (
                                <tr key={module.id}>
                                    <td className="py-2 pr-4">
                                        <code className="font-mono break-all text-gray-900">{module.path}</code>
                                    </td>
                                    <td className="py-2 pr-4 text-right text-gray-700 tabular-nums">{formatNumber(module.file_count)}</td>
                                    <td className="py-2 text-right text-gray-700 tabular-nums">{formatNumber(module.line_count)}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>

            <div className={cardClasses}>
                <h3 className="font-medium text-gray-900">Dependențe între module</h3>
                {dependencies.length === 0 ? (
                    <p className="mt-1 text-sm text-gray-500">Modulele nu se importă între ele.</p>
                ) : (
                    <>
                        <p className="mt-1 text-sm text-gray-500">
                            {dependencies.length < codeStructure.dependency_count &&
                                `Cele mai puternice ${dependencies.length} din ${codeStructure.dependency_count}. `}
                            Modulul din stânga importă fișiere din cel din dreapta.
                        </p>
                        <ul className="mt-4 divide-y divide-gray-100 text-sm">
                            {dependencies.map((dependency) => (
                                <li key={`${dependency.from}→${dependency.to}`} className="flex flex-wrap items-center justify-between gap-x-4 gap-y-1 py-2">
                                    <span className="flex min-w-0 flex-wrap items-center gap-2">
                                        <code className="font-mono break-all text-gray-900">{dependency.from}</code>
                                        <span aria-hidden="true" className="text-base text-gray-500">
                                            →
                                        </span>
                                        <span className="sr-only">importă din</span>
                                        <code className="font-mono break-all text-gray-900">{dependency.to}</code>
                                    </span>
                                    <span className="text-gray-500 tabular-nums">
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
