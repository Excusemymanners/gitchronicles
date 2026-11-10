type Props = {
    /**
     * Commit-urile repository-ului, grupate pe luni.
     * Deocamdată serverul trimite mereu `null`; forma datelor o stabilește partea care completează secțiunea.
     */
    activity: null;
};

/**
 * „Activitate” pe pagina Prezentare: cât s-a lucrat la proiect în fiecare lună, din istoricul git.
 * Fără date secțiunea nu apare deloc: nu afișăm nimic inventat.
 */
export default function ActivitySection({ activity }: Props) {
    return activity ?? null;
}
