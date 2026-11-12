import type { ReactNode } from 'react';
import { useI18n } from '@/hooks/use-i18n';
import SectionCard from '@/components/repository/section-card';

type Props = {
    title: string;
    /** Ce va arăta tab-ul, pe scurt. */
    children: ReactNode;
};

/** Conținutul unui tab care nu e gata încă: spune doar ce va apărea aici, fără date inventate. */
export default function ComingSoon({ title, children }: Props) {
    const { t } = useI18n();

    return (
        <SectionCard title={title}>
            <p className="text-sm font-medium text-ink">{t('overview.soon')}</p>
            <p className="mt-2 text-sm text-ink-muted">{children}</p>
        </SectionCard>
    );
}
