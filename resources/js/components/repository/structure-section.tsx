import CodeStructureSection from '@/components/code-structure';
import SectionCard from '@/components/repository/section-card';
import { useI18n } from '@/hooks/use-i18n';
import type { CodeStructure } from '@/types';

type Props = {
    /** The code analysis's result (Faza 5); `null` until the first analysis ends. */
    structure: CodeStructure | null;
};

/**
 * "Code structure" on the Overview: the languages, the modules and the strongest dependencies between them, as the
 * code analysis found them. Without data the section is left out: nothing invented.
 */
export default function StructureSection({ structure }: Props) {
    const { t } = useI18n();

    if (!structure) {
        return null;
    }

    return (
        <SectionCard title={t('overview.structure.title')}>
            <CodeStructureSection codeStructure={structure} />
        </SectionCard>
    );
}
