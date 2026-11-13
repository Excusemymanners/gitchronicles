import { useEffect, useState } from 'react';
import type { DetectedTechnology } from '@/components/diagrams/types';
import { LOGO_TILE_FILL } from '@/lib/mermaid';

type Props = {
    technologies: DetectedTechnology[];
};

type IconData = { body: string; width?: number; height?: number };
type IconPack = { icons: Record<string, IconData>; width?: number; height?: number };

/**
 * „Tehnologii găsite”: fiecare tehnologie din diagrama de containere, cu logo-ul ei și fișierul
 * în care am găsit-o, ca oricine să poată verifica de unde vine.
 */
export default function TechnologyList({ technologies }: Props) {
    const [icons, setIcons] = useState<IconPack | null>(null);

    // Aceleași logo-uri ca în diagrame, încărcate la nevoie (le are deja încărcate și Mermaid).
    useEffect(() => {
        import('@/lib/diagram-icons.json').then((module) => setIcons(module.default as IconPack));
    }, []);

    return (
        <ul className="flex flex-wrap gap-2">
            {technologies.map((technology) => (
                <li key={technology.name} className="flex items-center gap-2 rounded-md border border-line bg-surface py-1 pr-3 pl-1.5 text-sm">
                    <Logo pack={icons} name={technology.icon} />
                    <span className="font-medium text-ink">{technology.name}</span>
                    <code className="font-mono text-xs text-ink-subtle">{technology.source}</code>
                </li>
            ))}
        </ul>
    );
}

/**
 * Logo-ul pe o plăcuță deschisă la culoare, ca în diagrame: așa se văd și logo-urile negre (GitHub)
 * în tema întunecată. Fără logo rămâne doar plăcuța.
 */
function Logo({ pack, name }: { pack: IconPack | null; name: string | null }) {
    const icon = name && pack ? pack.icons[name.replace(/^logos:/, '')] : undefined;
    const width = icon?.width ?? pack?.width ?? 256;
    const height = icon?.height ?? pack?.height ?? 256;
    const size = Math.max(width, height);
    const padding = size * 0.18;
    const viewBox = [-(size - width) / 2 - padding, -(size - height) / 2 - padding, size + 2 * padding, size + 2 * padding].join(' ');

    return (
        <svg aria-hidden="true" width="22" height="22" viewBox={viewBox} className="shrink-0">
            <rect x={-(size - width) / 2 - padding} y={-(size - height) / 2 - padding} width={size + 2 * padding} height={size + 2 * padding} rx={size * 0.22} fill={LOGO_TILE_FILL} />
            {/* Desenul logo-ului vine din fișierul nostru diagram-icons.json (pachetul Iconify „logos”, CC0), nu din date. */}
            {icon && <g dangerouslySetInnerHTML={{ __html: icon.body }} />}
        </svg>
    );
}
