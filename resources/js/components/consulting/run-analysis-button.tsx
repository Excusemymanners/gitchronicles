import { Form } from '@inertiajs/react';
import { useI18n } from '@/hooks/use-i18n';

type Props = {
    repositoryId: number;
    /** „Rulează din nou” când există deja un raport, „Rulează analiza” altfel. */
    hasReport: boolean;
};

/**
 * Butonul care pune analiza în coadă (doar pentru utilizatorii autentificați, RepositoryPolicy::runConsulting).
 * E un formular obișnuit: serverul răspunde cu o redirecționare înapoi pe pagină, care apoi urmărește analiza.
 */
export default function RunAnalysisButton({ repositoryId, hasReport }: Props) {
    const { t } = useI18n();

    return (
        <Form action={`/repositories/${repositoryId}/consulting`} method="post" options={{ preserveScroll: true }}>
            {({ processing }) => (
                <button
                    type="submit"
                    disabled={processing}
                    className="pressable inline-flex cursor-pointer items-center rounded-md bg-surface px-3 py-1.5 text-sm font-medium text-ink shadow-card ring-1 ring-line-strong ring-inset hover:bg-surface-2 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent disabled:cursor-not-allowed disabled:opacity-60"
                >
                    {processing ? t('consulting.run.starting') : hasReport ? t('consulting.run.again') : t('consulting.run.start')}
                </button>
            )}
        </Form>
    );
}
