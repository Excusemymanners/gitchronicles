import type { ReactNode } from 'react';
import { AssistantIcon, CommitIcon, CountIcon, DiagramIcon } from '@/components/icons';
import { useI18n } from '@/hooks/use-i18n';

type ProductPromise = {
    icon: ReactNode;
    /** The key in the dictionary, under "home.promises.{key}" (title, text). */
    key: 'current' | 'real' | 'cited' | 'visible';
};

/** The four things GitChronicle stands for, in the order a visitor asks about them. */
const promises: ProductPromise[] = [
    { icon: <CommitIcon />, key: 'current' },
    { icon: <CountIcon />, key: 'real' },
    { icon: <AssistantIcon />, key: 'cited' },
    { icon: <DiagramIcon />, key: 'visible' },
];

/**
 * The product's promises as one tight row under the hero: four short statements separated by hairlines, no cards
 * and no illustrations. Two columns on a tablet, one on a phone.
 */
export default function Promises() {
    const { t } = useI18n();

    return (
        <section aria-labelledby="promises-heading">
            <h2 id="promises-heading" className="sr-only">
                {t('home.promises.title')}
            </h2>
            <dl className="grid gap-px overflow-hidden rounded-lg border border-line bg-line sm:grid-cols-2 lg:grid-cols-4">
                {promises.map((promise) => (
                    <div key={promise.key} className="bg-canvas p-5 lg:p-6">
                        <dt className="flex items-center gap-2.5 text-[0.9375rem] font-semibold text-ink">
                            <span className="text-ink-subtle [&_svg]:size-[1.125rem]">{promise.icon}</span>
                            {t(`home.promises.${promise.key}.title`)}
                        </dt>
                        <dd className="mt-2 text-sm leading-relaxed text-ink-muted">{t(`home.promises.${promise.key}.text`)}</dd>
                    </div>
                ))}
            </dl>
        </section>
    );
}
