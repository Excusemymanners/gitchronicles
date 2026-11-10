import CodeStructureSection from '@/components/code-structure';
import type { CodeStructure } from '@/types';

type Props = {
    /** Structura codului din analiză (Faza 5); `null` până se termină prima analiză. */
    structure: CodeStructure | null;
};

/**
 * „Structura codului” pe pagina Prezentare: cifrele principale, limbajele, modulele și dependențele
 * dintre ele, din analiza codului.
 * Fără date secțiunea nu apare deloc: nu afișăm nimic inventat.
 */
export default function StructureSection({ structure }: Props) {
    if (!structure) {
        return null;
    }

    return (
        <section aria-labelledby="code-structure-heading">
            <h2 id="code-structure-heading" className="text-lg font-semibold tracking-tight">
                Structura codului
            </h2>
            <CodeStructureSection codeStructure={structure} />
        </section>
    );
}
