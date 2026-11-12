import type { ReactNode } from 'react';
import { AssistantIcon, DiagramIcon, DocumentIcon, HistoryIcon, VideoIcon } from '@/components/icons';
import { useI18n } from '@/hooks/use-i18n';
import type en from '@/lang/en';

type Capability = {
    icon: ReactNode;
    /** Cheia din dicționar, la „home.capabilities.{key}” (title, text). */
    key: Exclude<keyof typeof en.home.capabilities, 'title'>;
};

/** Capitolele pe care GitChronicle le scrie pentru fiecare repository, în ordinea din cuprinsul lui. */
const capabilities: Capability[] = [
    {
        icon: <DocumentIcon className="size-5" />,
        key: 'docs',
    },
    {
        icon: <DiagramIcon className="size-5" />,
        key: 'diagrams',
    },
    {
        icon: <AssistantIcon className="size-5" />,
        key: 'assistant',
    },
    {
        icon: <HistoryIcon className="size-5" />,
        key: 'history',
    },
    {
        icon: <VideoIcon className="size-5" />,
        key: 'video',
    },
];

/**
 * „Ce găsești în fiecare repository”: cele cinci capitole, ca o listă de definiții pe coloane.
 * Fără carduri: fiecare coloană e despărțită doar de o linie subțire deasupra.
 */
export default function Capabilities() {
    const { t } = useI18n();

    return (
        <dl className="grid gap-x-8 gap-y-8 sm:grid-cols-2 lg:grid-cols-5 lg:gap-x-6">
            {capabilities.map((capability) => (
                <div key={capability.key} className="border-t border-line pt-5">
                    <dt className="flex items-center gap-2.5 font-semibold text-ink lg:flex-col lg:items-start lg:gap-3">
                        <span className="text-accent">{capability.icon}</span>
                        {t(`home.capabilities.${capability.key}.title`)}
                    </dt>
                    <dd className="mt-2 text-sm leading-6 text-ink-muted">{t(`home.capabilities.${capability.key}.text`)}</dd>
                </div>
            ))}
        </dl>
    );
}
