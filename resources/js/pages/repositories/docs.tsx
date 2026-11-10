import { Head } from '@inertiajs/react';
import ComingSoon from '@/components/repository/coming-soon';
import RepositoryLayout from '@/layouts/repository-layout';
import type { RepositoryPageProps } from '@/types';

/** Tab-ul „Documentație”. Deocamdată spune doar ce va apărea aici. */
export default function RepositoriesDocs({ repository }: RepositoryPageProps) {
    return (
        <RepositoryLayout>
            <Head title={`Documentație · ${repository.full_name}`} />

            <ComingSoon title="Documentație">
                O pagină de ansamblu despre proiect (ce face, ce tehnologii folosește, cum îl rulezi) și câte o pagină pentru fiecare modul, scrise din codul repository-ului, cu legături la fișierele și liniile de pe GitHub.
            </ComingSoon>
        </RepositoryLayout>
    );
}
