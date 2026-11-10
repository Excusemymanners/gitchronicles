type Props = {
    /**
     * Fișierele schimbate cel mai des și contribuitorii, din istoricul git.
     * Deocamdată serverul trimite mereu `null`; forma datelor o stabilește partea care completează secțiunea.
     */
    hotspots: null;
};

/**
 * „Zone fierbinți” pe pagina Prezentare: fișierele schimbate cel mai des (acolo apar de obicei bug-urile
 * și pe ele trebuie să le înțeleagă primele un nou venit) și cine a contribuit.
 * Fără date secțiunea nu apare deloc: nu afișăm nimic inventat.
 */
export default function HotspotsSection({ hotspots }: Props) {
    return hotspots ?? null;
}
