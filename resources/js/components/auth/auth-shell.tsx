import type { ReactNode } from 'react';
import PipelineRail from '@/components/pipeline-rail';
import { useI18n } from '@/hooks/use-i18n';
import { pageContainer } from '@/layouts/app-layout';

type Props = {
    title: string;
    /** O propoziție sau două sub titlu: de ce e nevoie de cont. */
    lead: string;
    /** Linkul repository-ului cerut înainte de autentificare; `null` dacă vizitatorul nu a cerut niciunul. */
    pendingRepository: string | null;
    /** Ce spunem despre repository-ul în așteptare, înaintea linkului („As soon as you log in, we’ll add”). */
    pendingText: string;
    /** Formularul. */
    children: ReactNode;
    /** Rândul de sub formular: legătura către cealaltă pagină (cont nou sau autentificare). */
    footer?: ReactNode;
};

/**
 * Rama comună a paginilor „Autentificare” și „Cont nou”: formularul în stânga, iar în dreapta (pe ecrane late)
 * ce se întâmplă cu un repository după ce îl adaugi, pe aceeași linie cu pași ca pe prima pagină.
 * Așa, cele două pagini arată ca restul produsului și spun pentru ce e contul.
 */
export default function AuthShell({ title, lead, pendingRepository, pendingText, children, footer }: Props) {
    const { t } = useI18n();

    return (
        <div className={`grid flex-1 items-start gap-x-16 gap-y-12 py-12 sm:py-16 lg:grid-cols-[minmax(0,5fr)_minmax(0,6fr)] lg:py-24 ${pageContainer}`}>
            <div className="mx-auto w-full max-w-sm lg:mx-0">
                <h1 className="font-display text-title font-bold text-ink">{title}</h1>
                <p className="mt-3 text-base leading-7 text-ink-muted">{lead}</p>

                {pendingRepository && (
                    <p role="status" className="mt-6 rounded-lg border border-info-line bg-info-soft px-4 py-3 text-sm text-info-ink">
                        {pendingText} <span className="font-medium break-all">{pendingRepository}</span>.
                    </p>
                )}

                <div className="mt-8">{children}</div>

                {footer && <p className="mt-6 text-sm text-ink-muted">{footer}</p>}
            </div>

            <aside aria-labelledby="after-heading" className="rounded-xl border border-line bg-surface p-6 shadow-card max-lg:hidden xl:p-8">
                <h2 id="after-heading" className="font-display text-lg font-semibold tracking-tight text-ink">
                    {t('auth.after.title')}
                </h2>
                <p className="mt-1 text-sm text-ink-muted">{t('auth.after.lead')}</p>
                <PipelineRail animated className="mt-6" />
            </aside>
        </div>
    );
}
