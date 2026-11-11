import type { ReactNode } from 'react';
import { AssistantIcon, DiagramIcon, DocumentIcon, HistoryIcon, VideoIcon } from '@/components/icons';

type Capability = {
    icon: ReactNode;
    title: string;
    text: string;
};

/** Capitolele pe care GitChronicle le scrie pentru fiecare repository, în ordinea din cuprinsul lui. */
const capabilities: Capability[] = [
    {
        icon: <DocumentIcon className="size-5" />,
        title: 'Documentație',
        text: '„Despre proiect”, o pagină pentru fiecare modul important și un README. Fiecare pagină spune din ce commit a fost scrisă.',
    },
    {
        icon: <DiagramIcon className="size-5" />,
        title: 'Diagrame de arhitectură',
        text: 'Straturi, C4 (context, containere, componente) și fluxuri, desenate din importurile reale dintre module.',
    },
    {
        icon: <AssistantIcon className="size-5" />,
        title: 'Asistent cu citări',
        text: 'Întrebi despre cod în cuvintele tale. Răspunsul trimite la fișierul și la liniile pe care se sprijină.',
    },
    {
        icon: <HistoryIcon className="size-5" />,
        title: 'Istoric și călătorie în timp',
        text: 'Commit-urile, fișierele schimbate cel mai des și contribuitorii. Poți vedea documentația așa cum era la un commit mai vechi.',
    },
    {
        icon: <VideoIcon className="size-5" />,
        title: 'Video de prezentare',
        text: 'Un clip vertical cu voce și un set de slide-uri (PDF și PPTX), făcute din datele repository-ului.',
    },
];

/**
 * „Ce găsești în fiecare repository”: cele cinci capitole, ca o listă de definiții pe coloane.
 * Fără carduri: fiecare coloană e despărțită doar de o linie subțire deasupra.
 */
export default function Capabilities() {
    return (
        <dl className="grid gap-x-8 gap-y-8 sm:grid-cols-2 lg:grid-cols-5 lg:gap-x-6">
            {capabilities.map((capability) => (
                <div key={capability.title} className="border-t border-line pt-5">
                    <dt className="flex items-center gap-2.5 font-semibold text-ink lg:flex-col lg:items-start lg:gap-3">
                        <span className="text-accent">{capability.icon}</span>
                        {capability.title}
                    </dt>
                    <dd className="mt-2 text-sm leading-6 text-ink-muted">{capability.text}</dd>
                </div>
            ))}
        </dl>
    );
}
