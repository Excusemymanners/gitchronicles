import { Link, usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { useI18n } from '@/hooks/use-i18n';
import RegenerateButton from '@/components/repository/regenerate-button';
import type { RepositoryPageProps } from '@/types';

type Props = {
    /** Ce lipsește, într-o propoziție scurtă. */
    title: string;
    /** De ce lipsește și ce o să-l facă. */
    children: ReactNode;
    /** Adevărat când o procesare completă îl poate face: oferim „Regenerează tot” (sau intrarea în cont). */
    offerRegenerate?: boolean;
};

const focusRing = 'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent';

/**
 * Starea goală a unui capitol (documentație, diagrame, asistent, istoric): spune ce lipsește și de ce,
 * fără conținut inventat, și oferă acțiunea care îl face, când există una.
 */
export default function ChapterEmptyState({ title, children, offerRegenerate = false }: Props) {
    return (
        <div className="rounded-lg border border-dashed border-line-strong px-6 py-10 text-center text-sm text-ink-muted">
            <p className="font-medium text-ink">{title}</p>
            <div className="mx-auto mt-2 max-w-[60ch] leading-relaxed">{children}</div>
            {offerRegenerate && <RegenerateOffer className="mt-5 justify-center" />}
        </div>
    );
}

/**
 * „Regenerează tot” pentru un utilizator autentificat; un vizitator e invitat să intre în cont.
 * Cât timp repository-ul se procesează nu apare nimic: rularea e deja în curs.
 */
export function RegenerateOffer({ className = '' }: { className?: string }) {
    const { t, rich } = useI18n();
    const { props } = usePage<RepositoryPageProps>();
    const { auth, repository, can_regenerate } = props;

    if (can_regenerate) {
        return (
            <div className={`flex ${className}`}>
                <RegenerateButton repository={repository} variant="primary" />
            </div>
        );
    }

    if (auth.user || repository.is_processing) {
        return null;
    }

    return (
        <p className={`flex ${className}`}>
            <span>
                {rich('repository.regenerate.logInToStart', {
                    logIn: (
                        <Link href="/login" className={`rounded-sm font-medium text-accent hover:text-accent-hover hover:underline ${focusRing}`}>
                            {t('nav.logIn')}
                        </Link>
                    ),
                })}
            </span>
        </p>
    );
}
