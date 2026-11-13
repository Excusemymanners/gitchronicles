import { Form, Head, usePage } from '@inertiajs/react';
import PipelineRail from '@/components/pipeline-rail';
import PrimaryButton from '@/components/primary-button';
import { FieldError } from '@/components/text-field';
import { GitHubIcon } from '@/components/workspace/icons';
import { useI18n } from '@/hooks/use-i18n';
import AppLayout, { pageContainer } from '@/layouts/app-layout';
import { formatDate, formatNumber, formatRelative } from '@/lib/format';
import type { GitHubFacts } from '@/types/front-door';

type Props = {
    /** „proprietar/nume”: cum l-a scris GitHub, sau cum l-a scris vizitatorul când GitHub n-a răspuns. */
    full_name: string;
    /** Adresa canonică trimisă formularului de adăugare (POST /repositories). */
    url: string;
    /** Faptele de pe GitHub; `null` când GitHub nu a putut fi întrebat. */
    facts: GitHubFacts | null;
};

const focusRing = 'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent';

/**
 * „Documentează acest repository”: pagina la care ajunge cine schimbă github.com cu adresa noastră într-un link
 * de repository nedocumentat încă. Arată faptele reale de la GitHub și o singură acțiune, care folosește fluxul
 * obișnuit de adăugare: utilizatorul autentificat îl adaugă pe loc, vizitatorul trece întâi prin autentificare.
 * Fără fapte (GitHub nu răspunde), pagina spune asta și nu inventează nimic. Pagina nu e indexată de motoare de căutare.
 */
export default function FrontDoorDocument({ full_name, url, facts }: Props) {
    const { auth } = usePage().props;
    const { t } = useI18n();

    return (
        <AppLayout>
            <Head title={`${t('frontDoor.title')} · ${full_name}`}>
                <meta head-key="robots" name="robots" content="noindex" />
            </Head>

            <section className={`grid gap-x-16 gap-y-12 pt-12 pb-16 sm:pt-16 lg:grid-cols-[minmax(0,7fr)_minmax(0,5fr)] lg:pb-24 ${pageContainer}`}>
                <div>
                    <p className="text-sm font-medium text-accent">{t('frontDoor.title')}</p>

                    <div className="mt-3 flex items-center gap-4">
                        {facts?.avatar_url && (
                            <img src={facts.avatar_url} alt="" width={56} height={56} referrerPolicy="no-referrer" className="size-14 shrink-0 rounded-xl ring-1 ring-line" />
                        )}
                        <h1 className="min-w-0 font-display text-title font-semibold break-words text-ink">{full_name}</h1>
                    </div>

                    {facts?.description && <p className="mt-4 max-w-[60ch] text-base leading-7 text-ink-muted">{facts.description}</p>}

                    {facts ? <Facts facts={facts} /> : <Unavailable />}

                    <Form action="/repositories" method="post" className="mt-8 max-w-xl">
                        {({ errors, processing }) => (
                            <>
                                <input type="hidden" name="url" value={url} />
                                <div className="flex flex-wrap items-center gap-x-4 gap-y-3">
                                    <PrimaryButton size="lg" loading={processing}>
                                        {processing ? t('frontDoor.submitting') : t('frontDoor.action')}
                                    </PrimaryButton>
                                    <a
                                        href={`https://github.com/${full_name}`}
                                        target="_blank"
                                        rel="noreferrer"
                                        className={`rounded-md text-sm font-medium text-ink-muted hover:text-ink ${focusRing}`}
                                    >
                                        <GitHubIcon className="mr-1.5 inline size-4 align-text-bottom" />
                                        {t('frontDoor.openOnGitHub')}
                                        <span className="sr-only"> {t('common.opensInNewTab')}</span>
                                    </a>
                                </div>
                                {errors.url && <FieldError id="url-error" className="mt-3">{errors.url}</FieldError>}
                                <p className="mt-3 text-sm text-ink-subtle">{auth.user ? t('frontDoor.hintUser') : t('frontDoor.hintGuest')}</p>
                            </>
                        )}
                    </Form>
                </div>

                <section aria-labelledby="next-heading" className="lg:pt-3">
                    <h2 id="next-heading" className="font-display text-lg font-semibold tracking-tight text-ink">
                        {t('frontDoor.willDoTitle')}
                    </h2>
                    <p className="mt-1 text-sm text-ink-muted">{t('frontDoor.lead', { repository: full_name })}</p>
                    <PipelineRail className="mt-6" />
                </section>
            </section>
        </AppLayout>
    );
}

/** Cifrele și datele de la GitHub; lipsește orice câmp pe care GitHub nu l-a dat. */
function Facts({ facts }: { facts: GitHubFacts }) {
    const { t } = useI18n();

    const items: { label: string; value: string; title?: string }[] = [
        { label: t('frontDoor.facts.stars'), value: formatNumber(facts.stars) },
        { label: t('frontDoor.facts.forks'), value: formatNumber(facts.forks) },
    ];

    if (facts.language) {
        items.push({ label: t('frontDoor.facts.language'), value: facts.language });
    }

    if (facts.license) {
        items.push({ label: t('frontDoor.facts.license'), value: facts.license });
    }

    if (facts.pushed_at) {
        items.push({ label: t('frontDoor.facts.lastPush'), value: formatRelative(facts.pushed_at), title: formatDate(facts.pushed_at) });
    }

    if (facts.default_branch) {
        items.push({ label: t('frontDoor.facts.defaultBranch'), value: facts.default_branch });
    }

    return (
        <dl className="mt-8 grid max-w-xl grid-cols-2 gap-x-6 gap-y-5 rounded-xl border border-line bg-surface p-5 shadow-card sm:grid-cols-3">
            {items.map((item) => (
                <div key={item.label}>
                    <dt className="text-small text-ink-subtle">{item.label}</dt>
                    <dd title={item.title} className="mt-1 truncate font-medium text-ink tabular-nums">
                        {item.value}
                    </dd>
                </div>
            ))}
        </dl>
    );
}

/** GitHub n-a răspuns (sau limita de cereri e atinsă): o spunem, fără cifre inventate. */
function Unavailable() {
    const { t } = useI18n();

    return (
        <div role="status" className="mt-8 max-w-xl rounded-xl border border-dashed border-line-strong px-5 py-4 text-sm">
            <p className="font-medium text-ink">{t('frontDoor.unavailable.title')}</p>
            <p className="mt-1 text-ink-muted">{t('frontDoor.unavailable.text')}</p>
        </div>
    );
}
