import { Head } from '@inertiajs/react';
import TimelineContent from '@/components/timeline/timeline-content';
import type { TimelineContentProps } from '@/components/timeline/timeline-content';
import RepositoryLayout from '@/layouts/repository-layout';
import type { RepositoryPageProps } from '@/types';

type Props = RepositoryPageProps & Omit<TimelineContentProps, 'repository'>;

/** Tab-ul „Istoric”: antetul repository-ului și capitolul cu istoricul (TimelineContent). */
export default function RepositoriesTimeline({ repository, summary, commits, runs, hotspots, contributors, filters }: Props) {
    return (
        <RepositoryLayout>
            <Head title={`Istoric · ${repository.full_name}`} />

            <TimelineContent
                repository={repository}
                summary={summary}
                commits={commits}
                runs={runs}
                hotspots={hotspots}
                contributors={contributors}
                filters={filters}
            />
        </RepositoryLayout>
    );
}
