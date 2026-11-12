import { Form, Head, Link, router, usePage } from '@inertiajs/react';
import { useI18n } from '@/hooks/use-i18n';
import AppLayout from '@/layouts/app-layout';
import { formatAge, formatDay, formatDecimal, formatNumber, formatSignedNumber } from '@/lib/format';
import type { DiscoverEntry, DiscoverPagination, DiscoverPeriodOption, DiscoverRanking } from '@/types/discover';

type Props = {
    /** Filtrele din adresă: perioada și limbajul (`null` = toate limbajele). */
    filters: { period: DiscoverPeriodOption; language: string | null };
    /** Perioadele filtrului, ca valori ('24h', '7d', '30d'); numele lor vin din dicționar („discover.periods”). */
    periods: DiscoverPeriodOption[];
    /** Perioada clasamentului implicit, din care vine „Repo-ul zilei”. */
    default_period: DiscoverPeriodOption;
    /** Limbajele din ziua clasamentului, cel mai des întâlnit primul. */
    languages: { name: string; count: number }[];
    ranking: DiscoverRanking;
    /** Repository-urile de pe pagina curentă. */
    entries: DiscoverEntry[];
    pagination: DiscoverPagination;
    /** Locul 1 din clasamentul implicit (7 zile, toate limbajele); `null` cât timp nu s-a adunat nimic. */
    repository_of_the_day: DiscoverEntry | null;
};

const focusRing = 'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent';

const secondaryButton = `inline-flex items-center justify-center rounded-md bg-surface px-3 py-1.5 text-sm font-medium whitespace-nowrap text-ink shadow-xs ring-1 ring-line-strong ring-inset hover:bg-surface-2 ${focusRing}`;

/** Adresa paginii cu filtrele date; schimbarea unui filtru pornește de la prima pagină. */
function discoverUrl(period: string, language: string | null): string {
    const query = new URLSearchParams({ period });

    if (language) {
        query.set('language', language);
    }

    return `/discover?${query.toString()}`;
}

/**
 * Pagina „Descoperă”: repository-urile publice de pe GitHub care câștigă stele cel mai repede,
 * din notările zilnice ale comenzii `discover:refresh`. Creșterea apare doar când e măsurată
 * (două notări la distanța perioadei); până atunci clasamentul folosește media de stele pe zi de la creare
 * și spune asta deasupra listei.
 */
export default function Discover({ filters, periods, default_period, languages, ranking, entries, pagination, repository_of_the_day }: Props) {
    const { t, plural, rich } = useI18n();
    const labelOf = (period: DiscoverPeriodOption): string => t(`discover.periods.${period}`);
    const periodLabel = labelOf(filters.period);
    const showsGrowth = ranking.ordered_by === 'growth';

    return (
        <AppLayout>
            <Head title={t('discover.title')} />

            <header>
                <h1 className="text-2xl font-semibold tracking-tight sm:text-3xl">{t('discover.title')}</h1>
                <p className="mt-2 max-w-2xl text-ink-muted">{t('discover.lead')}</p>
            </header>

            {ranking.day === null ? (
                <div className="mt-8 rounded-lg border border-dashed border-line-strong px-6 py-10 text-center text-sm text-ink-muted">
                    <p>{t('discover.empty.title')}</p>
                    <p className="mt-2">
                        {rich('discover.empty.text', {
                            refresh: <code className="rounded-sm bg-surface-2 px-1.5 py-0.5 font-mono text-ink">php artisan discover:refresh</code>,
                            schedule: <code className="rounded-sm bg-surface-2 px-1.5 py-0.5 font-mono text-ink">php artisan schedule:work</code>,
                        })}
                    </p>
                </div>
            ) : (
                <>
                    {repository_of_the_day && (
                        <RepositoryOfTheDay entry={repository_of_the_day} day={ranking.day} periodLabel={labelOf(default_period)} />
                    )}

                    <section aria-labelledby="ranking-heading" className="mt-10">
                        <div className="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
                            <h2 id="ranking-heading" className="text-lg font-semibold tracking-tight">
                                {rich('discover.rankingFor', { day: <time dateTime={ranking.day}>{formatDay(ranking.day)}</time> })}
                            </h2>
                            <p className="text-sm text-ink-subtle">{plural('repositories', ranking.total)}</p>
                        </div>

                        <div className="mt-4 flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
                            <nav aria-labelledby="period-label">
                                <p id="period-label" className="text-sm font-medium text-ink-muted">
                                    {t('discover.period')}
                                </p>
                                <div className="mt-1.5 inline-flex rounded-md border border-line bg-surface p-0.5 shadow-xs">
                                    {periods.map((period) => (
                                        <Link
                                            key={period}
                                            href={discoverUrl(period, filters.language)}
                                            preserveScroll
                                            aria-current={period === filters.period ? 'page' : undefined}
                                            className="rounded px-3 py-1.5 text-sm whitespace-nowrap text-ink-muted hover:bg-surface-2 hover:text-ink focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-accent aria-[current]:bg-accent-soft aria-[current]:font-medium aria-[current]:text-accent"
                                        >
                                            {labelOf(period)}
                                        </Link>
                                    ))}
                                </div>
                            </nav>

                            <div>
                                <label htmlFor="language" className="text-sm font-medium text-ink-muted">
                                    {t('discover.language')}
                                </label>
                                <select
                                    id="language"
                                    value={filters.language ?? ''}
                                    onChange={(event) => router.get(discoverUrl(filters.period, event.target.value || null), {}, { preserveScroll: true })}
                                    className="mt-1.5 block w-full rounded-md border border-line-strong bg-surface px-3 py-1.5 text-sm text-ink shadow-xs focus:outline-2 focus:-outline-offset-1 focus:outline-accent sm:w-60"
                                >
                                    <option value="">{t('discover.allLanguages')}</option>
                                    {/* Un limbaj din adresă care nu mai are repository-uri rămâne totuși selectat. */}
                                    {filters.language && !languages.some((language) => language.name === filters.language) && (
                                        <option value={filters.language}>{filters.language} (0)</option>
                                    )}
                                    {languages.map((language) => (
                                        <option key={language.name} value={language.name}>
                                            {language.name} ({formatNumber(language.count)})
                                        </option>
                                    ))}
                                </select>
                            </div>
                        </div>

                        <OrderingNote ranking={ranking} periodLabel={periodLabel} />

                        {entries.length === 0 ? (
                            <p className="mt-4 rounded-lg border border-dashed border-line-strong px-6 py-10 text-center text-sm text-ink-muted">
                                {filters.language
                                    ? t('discover.noEntriesInLanguage', { language: filters.language, day: formatDay(ranking.day) })
                                    : t('discover.noEntries', { day: formatDay(ranking.day) })}
                            </p>
                        ) : (
                            <ol className="mt-4 divide-y divide-line rounded-lg border border-line bg-surface shadow-xs">
                                {entries.map((entry) => (
                                    <li key={entry.id} className="flex gap-3 px-4 py-4 sm:gap-4 sm:px-6">
                                        <span className="w-7 shrink-0 pt-0.5 text-right text-base font-semibold tabular-nums text-ink-subtle sm:w-8">
                                            <span className="sr-only">{t('discover.rank')} </span>
                                            {entry.position}
                                        </span>
                                        <EntrySummary entry={entry} periodLabel={periodLabel} showsGrowth={showsGrowth} />
                                    </li>
                                ))}
                            </ol>
                        )}

                        {pagination.last_page > 1 && (
                            <nav aria-label={t('discover.pagination')} className="mt-6 flex items-center justify-between gap-3 text-sm">
                                {pagination.previous_url ? (
                                    <Link href={pagination.previous_url} className={secondaryButton}>
                                        {t('discover.previous')}
                                    </Link>
                                ) : (
                                    <span />
                                )}
                                <span className="text-ink-subtle">
                                    {t('discover.pageOf', { current: pagination.current_page, last: pagination.last_page })}
                                </span>
                                {pagination.next_url ? (
                                    <Link href={pagination.next_url} className={secondaryButton}>
                                        {t('discover.next')}
                                    </Link>
                                ) : (
                                    <span />
                                )}
                            </nav>
                        )}
                    </section>
                </>
            )}
        </AppLayout>
    );
}

/** „Repo-ul zilei”: locul 1 din clasamentul implicit, evidențiat deasupra listei. */
function RepositoryOfTheDay({ entry, day, periodLabel }: { entry: DiscoverEntry; day: string; periodLabel: string }) {
    const { rich } = useI18n();

    // Pe fundalul colorat, etichetele cifrelor sunt mai închise (ink-muted), ca să rămână lizibile.
    return (
        <section
            aria-labelledby="repository-of-the-day"
            className="mt-8 rounded-lg border border-accent/40 bg-accent-soft p-4 sm:p-6 [&_dt]:text-ink-muted"
        >
            <p id="repository-of-the-day" className="text-xs font-semibold tracking-wide text-accent uppercase">
                {rich('discover.repositoryOfTheDay', { day: <time dateTime={day}>{formatDay(day)}</time> })}
            </p>
            <div className="mt-2">
                <EntrySummary entry={entry} periodLabel={periodLabel} showsGrowth={entry.growth !== null} large />
            </div>
        </section>
    );
}

/**
 * Conținutul unui repository din clasament: numele și descrierea, cifrele și acțiunea.
 * Pe telefon totul e într-o coloană, cu butonul la final; de la `sm` butonul trece în dreapta.
 */
function EntrySummary({
    entry,
    periodLabel,
    showsGrowth,
    large = false,
}: {
    entry: DiscoverEntry;
    periodLabel: string;
    showsGrowth: boolean;
    large?: boolean;
}) {
    return (
        <div className="flex min-w-0 flex-1 flex-col gap-3 sm:grid sm:grid-cols-[minmax(0,1fr)_auto] sm:gap-x-6">
            <div className="min-w-0">
                <GitHubLink entry={entry} large={large} />
                {entry.description && (
                    <p className={`mt-1 text-sm break-words text-ink-muted ${large ? '' : 'line-clamp-2'}`}>{entry.description}</p>
                )}
            </div>
            <EntryNumbers entry={entry} periodLabel={periodLabel} showsGrowth={showsGrowth} />
            <div className="sm:col-start-2 sm:row-span-2 sm:row-start-1">
                <DocumentAction entry={entry} />
            </div>
        </div>
    );
}

/** Numele repository-ului, cu legătura spre GitHub (se deschide într-o filă nouă). */
function GitHubLink({ entry, large = false }: { entry: DiscoverEntry; large?: boolean }) {
    const { t } = useI18n();

    return (
        <a
            href={entry.github_url}
            target="_blank"
            rel="noopener noreferrer"
            className={`rounded-sm font-semibold break-words text-ink hover:text-accent ${large ? 'text-lg' : ''} ${focusRing}`}
        >
            {entry.full_name}
            <span aria-hidden="true" className="ml-1 text-ink-subtle">
                {'↗︎'}
            </span>
            <span className="sr-only"> {t('common.opensInNewTab')}</span>
        </a>
    );
}

/** Cifrele unui repository, ca listă de termeni: limbaj, stele, creștere, medie, vârstă. */
function EntryNumbers({ entry, periodLabel, showsGrowth }: { entry: DiscoverEntry; periodLabel: string; showsGrowth: boolean }) {
    const { t } = useI18n();

    return (
        <dl className="flex flex-wrap gap-x-6 gap-y-2 text-sm sm:col-start-1">
            {entry.language && <Figure label={t('discover.figures.language')} value={entry.language} />}
            <Figure label={t('discover.figures.stars')} value={formatNumber(entry.stars)} />
            {/* Creșterea apare doar când clasamentul e ordonat după ea; lipsa unei notări se vede ca „necunoscut”, nu ca 0. */}
            {showsGrowth && (
                <Figure
                    label={t('discover.figures.growth', { period: periodLabel })}
                    value={entry.growth === null ? t('common.unknown') : t('discover.figures.growthValue', { value: formatSignedNumber(entry.growth) })}
                    emphasized={entry.growth !== null}
                />
            )}
            <Figure
                label={t('discover.figures.average')}
                value={t('discover.figures.averageValue', { value: formatDecimal(entry.average_per_day) })}
                emphasized={!showsGrowth}
            />
            <Figure label={t('discover.figures.age')} value={formatAge(entry.age_days)} />
        </dl>
    );
}

function Figure({ label, value, emphasized = false }: { label: string; value: string; emphasized?: boolean }) {
    return (
        <div>
            <dt className="text-xs text-ink-subtle">{label}</dt>
            <dd className={`tabular-nums ${emphasized ? 'font-semibold text-ink' : 'text-ink-muted'}`}>{value}</dd>
        </div>
    );
}

/**
 * Acțiunea principală a unui rând: „Deschide documentația” când GitChronicle are deja repository-ul,
 * altfel „Documentează”, care trimite linkul la același formular ca pe pagina principală (POST /repositories).
 * Un vizitator fără cont ajunge la autentificare, iar repository-ul se adaugă automat după aceea.
 */
function DocumentAction({ entry }: { entry: DiscoverEntry }) {
    const { auth } = usePage().props;
    const { t } = useI18n();

    if (entry.documentation_url) {
        return (
            <Link href={entry.documentation_url} className={secondaryButton}>
                {t('discover.actions.open')}
            </Link>
        );
    }

    return (
        <Form action="/repositories" method="post" className="flex shrink-0 flex-col items-start gap-1 sm:items-end">
            {({ errors, processing }) => (
                <>
                    <input type="hidden" name="url" value={entry.add_url} />
                    <button
                        type="submit"
                        disabled={processing}
                        aria-label={t('discover.actions.documentLabel', { name: entry.full_name })}
                        title={auth.user ? undefined : t('discover.actions.guestHint')}
                        className={`inline-flex cursor-pointer items-center justify-center rounded-md bg-accent-strong px-3 py-1.5 text-sm font-semibold whitespace-nowrap text-on-accent shadow-xs hover:bg-accent-strong-hover disabled:cursor-not-allowed disabled:opacity-60 ${focusRing}`}
                    >
                        {processing ? t('discover.actions.adding') : t('discover.actions.document')}
                    </button>
                    {errors.url && <p className="max-w-60 text-xs text-danger">{errors.url}</p>}
                </>
            )}
        </Form>
    );
}

/** Spune deasupra listei după ce e ordonat clasamentul și de ce. */
function OrderingNote({ ranking, periodLabel }: { ranking: DiscoverRanking; periodLabel: string }) {
    const { rich, t } = useI18n();

    if (ranking.day === null || ranking.baseline_day === null) {
        return null;
    }

    return (
        <p className="mt-4 rounded-lg border border-info-line bg-info-soft px-4 py-3 text-sm text-info-ink">
            {ranking.ordered_by === 'growth'
                ? rich('discover.ordering.growth', {
                      emphasis: <strong className="font-semibold">{t('discover.ordering.growthEmphasis', { period: periodLabel })}</strong>,
                      day: formatDay(ranking.day),
                      baseline: formatDay(ranking.baseline_day),
                  })
                : rich('discover.ordering.average', {
                      period: periodLabel,
                      baseline: formatDay(ranking.baseline_day),
                      firstSnapshot: ranking.first_day ? t('discover.ordering.firstSnapshot', { day: formatDay(ranking.first_day) }) : '',
                      emphasis: <strong className="font-semibold">{t('discover.ordering.averageEmphasis')}</strong>,
                  })}
        </p>
    );
}
