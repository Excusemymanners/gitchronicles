import type { ReactNode } from 'react';
import SectionCard from '@/components/repository/section-card';

type Props = {
    title: string;
    /** Ce va arăta tab-ul, pe scurt. */
    children: ReactNode;
};

/** Conținutul unui tab care nu e gata încă: spune doar ce va apărea aici, fără date inventate. */
export default function ComingSoon({ title, children }: Props) {
    return (
        <SectionCard title={title}>
            <p className="text-sm font-medium text-gray-900">Această secțiune apare în curând.</p>
            <p className="mt-2 text-sm text-gray-600">{children}</p>
        </SectionCard>
    );
}
