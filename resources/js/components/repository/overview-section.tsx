type Props = {
    /**
     * Rezumatul proiectului din pagina de ansamblu a documentației generate.
     * Deocamdată serverul trimite mereu `null`; forma datelor o stabilește partea care completează secțiunea.
     */
    overview: null;
};

/**
 * „Despre proiect” pe pagina Prezentare: ce face proiectul, tehnologiile și cum se rulează,
 * din documentația generată.
 * Fără date secțiunea nu apare deloc: nu afișăm nimic inventat.
 */
export default function OverviewSection({ overview }: Props) {
    return overview ?? null;
}
