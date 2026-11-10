import { Head } from '@inertiajs/react';
import type { ComponentProps } from 'react';
import ActivitySection from '@/components/repository/activity-section';
import DetailsSection from '@/components/repository/details-section';
import DiagramPreviewSection from '@/components/repository/diagram-preview-section';
import HotspotsSection from '@/components/repository/hotspots-section';
import OverviewSection from '@/components/repository/overview-section';
import StructureSection from '@/components/repository/structure-section';
import RepositoryLayout from '@/layouts/repository-layout';
import type { RepositoryPageProps } from '@/types';

/**
 * Pe lângă antet, serverul trimite câte un prop pentru fiecare secțiune. Tipul fiecăruia
 * e definit o singură dată, în componenta secțiunii, și e preluat de aici.
 */
type Props = RepositoryPageProps & {
    overview: ComponentProps<typeof OverviewSection>['overview'];
    /** Rezultatul analizei codului (Faza 5); `null` până se termină prima analiză. */
    code_structure: ComponentProps<typeof StructureSection>['structure'];
    diagram_preview: ComponentProps<typeof DiagramPreviewSection>['preview'];
    activity: ComponentProps<typeof ActivitySection>['activity'];
    hotspots: ComponentProps<typeof HotspotsSection>['hotspots'];
};

/**
 * Tab-ul „Prezentare”: rezumatul repository-ului. Fiecare secțiune apare doar când are date reale
 * de afișat; „Detalii” (datele etapelor) apare mereu.
 */
export default function RepositoriesShow({ repository, overview, code_structure, diagram_preview, activity, hotspots }: Props) {
    return (
        <RepositoryLayout>
            <Head title={repository.full_name} />

            <div className="flex flex-col gap-8">
                <OverviewSection overview={overview} />

                <StructureSection structure={code_structure} />

                <DiagramPreviewSection preview={diagram_preview} />

                <ActivitySection activity={activity} />

                <HotspotsSection hotspots={hotspots} />

                <DetailsSection repository={repository} />
            </div>
        </RepositoryLayout>
    );
}
