import { Head } from '@inertiajs/react';
import ComingSoon from '@/components/repository/coming-soon';
import RepositoryLayout from '@/layouts/repository-layout';
import type { RepositoryPageProps } from '@/types';

/** Tab-ul „Video”. Deocamdată spune doar ce va apărea aici. */
export default function RepositoriesMedia({ repository }: RepositoryPageProps) {
    return (
        <RepositoryLayout>
            <Head title={`Video · ${repository.full_name}`} />

            <ComingSoon title="Video">
                Un video scurt de prezentare și slide-uri despre repository, făcute din datele lui reale.
            </ComingSoon>
        </RepositoryLayout>
    );
}
