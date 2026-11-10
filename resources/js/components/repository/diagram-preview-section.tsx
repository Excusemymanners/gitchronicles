type Props = {
    /**
     * Diagrama de arhitectură aleasă pentru previzualizare.
     * Deocamdată serverul trimite mereu `null`; forma datelor o stabilește partea care completează secțiunea.
     */
    preview: null;
};

/**
 * Previzualizarea diagramei de arhitectură pe pagina Prezentare, cu legătură spre tab-ul „Diagrame”.
 * Fără date secțiunea nu apare deloc: nu afișăm nimic inventat.
 */
export default function DiagramPreviewSection({ preview }: Props) {
    return preview ?? null;
}
