import { Link, usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { splitHref } from '@/lib/workspace';

type Props = {
    href: string;
    className?: string;
    'aria-current'?: 'page';
    children: ReactNode;
};

/**
 * O legătură spre un capitol. Spre o secțiune a paginii deschise (aceeași cale, altă ancoră, de exemplu
 * „Straturi” când „Diagrame” e deja deschisă) e o legătură obișnuită „#…”: browserul derulează până la
 * secțiune, fără o cerere la server și fără să redeseneze pagina. Spre altă pagină e un Link Inertia.
 */
export default function ChapterLink({ href, ...props }: Props) {
    const { url } = usePage();
    const [path, hash] = splitHref(href);

    if (hash !== '' && path === splitHref(url)[0]) {
        return <a href={hash} {...props} />;
    }

    // Hover or focus starts fetching the page (Inertia keeps it for 30 s), so the click finds it ready.
    return <Link href={href} prefetch {...props} />;
}
