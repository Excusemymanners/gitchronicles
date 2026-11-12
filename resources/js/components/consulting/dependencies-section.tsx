import ReportSection from '@/components/consulting/report-section';
import SeverityBadge from '@/components/consulting/severity-badge';
import { ExternalLinkIcon } from '@/components/icons';
import { useI18n } from '@/hooks/use-i18n';
import type { ConsultingTool, DependencyFinding } from '@/types/consulting';

type PackageGroup = {
    key: string;
    package: string;
    ecosystem: string | null;
    installed: string | null;
    path: string | null;
    advisories: DependencyFinding[];
    /** Cea mai mare dintre versiunile care repară, când fiecare avertisment are una. */
    fixesAll: string | null;
};

/** Grupează avertismentele pe pachet (același ecosistem, nume și versiune), în ordinea primită (cel mai grav primul). */
function groupByPackage(findings: DependencyFinding[]): PackageGroup[] {
    const groups = new Map<string, PackageGroup>();

    for (const finding of findings) {
        const key = `${finding.ecosystem}|${finding.package}|${finding.installed_version}`;
        const group = groups.get(key) ?? {
            key,
            package: finding.package,
            ecosystem: finding.ecosystem,
            installed: finding.installed_version,
            path: finding.path,
            advisories: [],
            fixesAll: null,
        };

        group.advisories.push(finding);
        groups.set(key, group);
    }

    return [...groups.values()].map((group) => {
        const fixes = group.advisories.map((advisory) => advisory.fixed_version);

        return {
            ...group,
            fixesAll: fixes.every((fix) => fix !== null)
                ? ([...fixes] as string[]).sort((a, b) => b.localeCompare(a, undefined, { numeric: true }))[0]
                : null,
        };
    });
}

/**
 * „Dependențe vulnerabile”: pachetele din fișierele lock cu vulnerabilități cunoscute în baza de date OSV,
 * fiecare cu avertismentele lui, versiunea care le repară și legătura spre avertisment pe osv.dev.
 */
export default function DependenciesSection({ dependencies, osvScanner }: { dependencies: DependencyFinding[]; osvScanner: ConsultingTool | undefined }) {
    const { t, plural } = useI18n();
    const groups = groupByPackage(dependencies);
    const packagesScanned = osvScanner?.details.packages_scanned ?? null;

    return (
        <ReportSection
            id="dependente-vulnerabile"
            title={t('consulting.dependencies.title')}
            description={packagesScanned === null ? undefined : t('consulting.dependencies.description', { packages: plural('packages', packagesScanned) })}
        >
            {groups.length === 0 ? (
                <p className="rounded-lg border border-dashed border-line-strong px-4 py-6 text-center text-sm text-ink-muted">
                    {t('consulting.dependencies.empty')}
                </p>
            ) : (
                <ul className="divide-y divide-line rounded-lg border border-line bg-surface">
                    {groups.map((group) => (
                        <li key={group.key} className="px-3 py-3 text-sm">
                            <div className="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1">
                                <p className="min-w-0">
                                    <code className="font-mono text-xs font-medium break-all text-ink">{group.package}</code>{' '}
                                    <span className="text-xs text-ink-subtle">
                                        {t('consulting.dependencies.packageInfo', { ecosystem: group.ecosystem ?? '', version: group.installed ?? '', path: group.path ?? '' })}
                                    </span>
                                </p>
                                <p className="text-xs text-ink-muted">
                                    {group.fixesAll === null ? t('consulting.dependencies.notAllFixed') : t('consulting.dependencies.fixedByAll', { version: group.fixesAll })}
                                </p>
                            </div>
                            <ul className="mt-2 flex flex-col gap-2">
                                {group.advisories.map((advisory) => (
                                    <li key={advisory.id} className="flex flex-wrap items-center gap-x-2 gap-y-1">
                                        <SeverityBadge severity={advisory.severity} />
                                        {advisory.original_severity && <span className="text-xs text-ink-subtle">{advisory.original_severity}</span>}
                                        {advisory.advisory_url ? (
                                            <a
                                                href={advisory.advisory_url}
                                                target="_blank"
                                                rel="noopener noreferrer"
                                                className="inline-flex items-center gap-1 rounded-sm font-mono text-xs text-accent hover:text-accent-hover hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
                                            >
                                                {advisory.advisory_id}
                                                <ExternalLinkIcon className="size-3.5" />
                                                <span className="sr-only"> {t('consulting.dependencies.advisoryLink')}</span>
                                            </a>
                                        ) : (
                                            <code className="font-mono text-xs text-ink">{advisory.advisory_id}</code>
                                        )}
                                        <span lang="en" className="min-w-0 basis-full text-ink-muted sm:basis-auto sm:flex-1">
                                            {advisory.title}
                                        </span>
                                        <span className="text-xs text-ink-subtle">
                                            {advisory.fixed_version === null ? t('consulting.dependencies.noFix') : t('consulting.dependencies.fixedIn', { version: advisory.fixed_version })}
                                        </span>
                                    </li>
                                ))}
                            </ul>
                        </li>
                    ))}
                </ul>
            )}
        </ReportSection>
    );
}
