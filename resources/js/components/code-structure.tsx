import { ArrowRightIcon } from '@/components/icons';
import { useI18n } from '@/hooks/use-i18n';
import { formatNumber, formatPercent } from '@/lib/format';
import type { CodeStructure } from '@/types';

type Props = {
    codeStructure: CodeStructure;
};

/** Each part is a plain block on the page (no card): the section around them draws the only frame, a hairline. */
const cardClasses = 'min-w-0';

// Pe telefon: numele și bara pe un rând, cifrele dedesubt; de la „sm” în sus: toate trei pe un rând.
const languageRowClasses = 'grid grid-cols-[7rem_1fr] items-center gap-x-4 gap-y-1 text-sm sm:grid-cols-[9rem_1fr_11rem]';

/**
 * "Code structure": the languages (with a bar for their share of the lines), the strongest dependencies between
 * modules and the table of every module, as the code analysis found them. The key numbers (files, lines,
 * modules) are in the Overview's hero, so they are not repeated here.
 */
export default function CodeStructureSection({ codeStructure }: Props) {
    const { t, plural } = useI18n();
    const { languages, modules, dependencies } = codeStructure;


    return (
        <div className="grid gap-x-12 gap-y-10 lg:grid-cols-2">
            <div className={cardClasses}>
                <h3 className="font-medium text-ink">{t('overview.structure.languages')}</h3>
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
                                    {t('overview.structure.languageLines', { lines: plural('lines', language.line_count), share: formatPercent(share) })}
                                </span>
                            </li>
                        );
                    })}
                </ul>
            </div>

            <div className={cardClasses}>
                <h3 className="font-medium text-ink">{t('overview.structure.dependencies')}</h3>
                {dependencies.length === 0 ? (
                    <p className="mt-1 text-sm text-ink-subtle">{t('overview.structure.noDependencies')}</p>
                ) : (
                    <>
                        <p className="mt-1 text-sm text-ink-subtle">
                            {dependencies.length < codeStructure.dependency_count
                                ? t('overview.structure.dependenciesTopNote', {
                                      shown: formatNumber(dependencies.length),
                                      total: formatNumber(codeStructure.dependency_count),
                                  })
                                : t('overview.structure.dependenciesNote')}
                        </p>
                        <ul className="mt-4 divide-y divide-line-subtle text-sm">
                            {dependencies.map((dependency) => (
                                <li key={`${dependency.from}→${dependency.to}`} className="flex flex-wrap items-center justify-between gap-x-4 gap-y-1 py-2">
                                    <span className="flex min-w-0 flex-wrap items-center gap-2">
                                        <code className="font-mono break-all text-ink">{dependency.from}</code>
                                        <ArrowRightIcon className="size-3.5 text-ink-subtle" />
                                        <span className="sr-only">{t('overview.structure.importsFrom')}</span>
                                        <code className="font-mono break-all text-ink">{dependency.to}</code>
                                    </span>
                                    <span className="text-ink-subtle tabular-nums">
                                        {plural('imports', dependency.weight)}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    </>
                )}
            </div>

            <div className={`${cardClasses} lg:col-span-2`}>
                <h3 className="font-medium text-ink">{t('overview.structure.modules')}</h3>
                <p className="mt-1 text-sm text-ink-subtle">{t('overview.structure.modulesNote')}</p>
                {/* Un proiect mare poate avea sute de module: lista se derulează în casetă, cu antetul fix. */}
                <div className="mt-4 max-h-112 overflow-auto">
                    <table className="w-full text-left text-sm">
                        <thead className="sticky top-0 border-b border-line bg-canvas text-ink-subtle">
                            <tr>
                                <th scope="col" className="py-2 pr-4 font-medium">
                                    {t('overview.structure.module')}
                                </th>
                                <th scope="col" className="py-2 pr-4 text-right font-medium">
                                    {t('overview.structure.filesColumn')}
                                </th>
                                <th scope="col" className="py-2 text-right font-medium">
                                    {t('overview.structure.linesColumn')}
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
        </div>
    );
}
