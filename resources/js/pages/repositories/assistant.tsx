import { Head } from '@inertiajs/react';
import ComingSoon from '@/components/repository/coming-soon';
import RepositoryLayout from '@/layouts/repository-layout';
import type { RepositoryPageProps } from '@/types';

/** Tab-ul „Asistent”. Deocamdată spune doar ce va apărea aici. */
export default function RepositoriesAssistant({ repository }: RepositoryPageProps) {
    return (
        <RepositoryLayout>
            <Head title={`Asistent · ${repository.full_name}`} />

            <ComingSoon title="Asistent">
                Un chat în care pui întrebări despre cod. Fiecare răspuns citează fișierele și liniile pe care se bazează.
            </ComingSoon>
        </RepositoryLayout>
    );
}
