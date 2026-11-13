import { Head, Link } from '@inertiajs/react';
import { buttonClasses } from '@/components/button';
import { useI18n } from '@/hooks/use-i18n';
import AppLayout, { pageContainer } from '@/layouts/app-layout';

type Props = {
    /** „proprietar/nume”, așa cum l-a scris vizitatorul în adresă. */
    full_name: string;
};

const focusRing = 'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent';

/**
 * Repository-ul nu există pe GitHub sau este privat (GitHub răspunde la fel în ambele cazuri):
 * pagina 404 în stilul produsului, cu drumul spre prima pagină. Serverul răspunde cu starea 404 și nu o indexează.
 */
export default function FrontDoorNotFound({ full_name }: Props) {
    const { t } = useI18n();

    return (
        <AppLayout>
            <Head title={t('frontDoor.notFound.title', { repository: full_name })}>
                <meta head-key="robots" name="robots" content="noindex" />
            </Head>

            <section className={`py-16 sm:py-24 ${pageContainer}`}>
                <p className="font-mono text-sm text-ink-subtle">404</p>
                <h1 className="mt-3 max-w-[22ch] font-display text-title font-semibold break-words text-ink">
                    {t('frontDoor.notFound.title', { repository: full_name })}
                </h1>
                <p className="mt-4 max-w-[58ch] text-base leading-7 text-ink-muted">{t('frontDoor.notFound.text')}</p>

                <div className="mt-8 flex flex-wrap items-center gap-x-5 gap-y-3">
                    <Link href="/" className={buttonClasses({ variant: 'primary', size: 'lg' })}>
                        {t('frontDoor.notFound.home')}
                    </Link>
                    <Link href="/discover" className={`rounded-md text-sm font-medium text-ink-muted hover:text-ink ${focusRing}`}>
                        {t('frontDoor.notFound.discover')}
                    </Link>
                </div>
            </section>
        </AppLayout>
    );
}
