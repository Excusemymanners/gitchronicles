import { useSyncExternalStore } from 'react';
import { getLocale, pluralize, subscribeToLocale, translate, translateRich } from '@/lib/i18n';

/**
 * Textele interfeței în limba aleasă, pentru o componentă React:
 *
 *   const { t, plural, rich, locale } = useI18n();
 *   t('home.hero.title')                          → „Documentation that keeps up with every commit.”
 *   t('discover.pageOf', { current: 2, last: 5 }) → „Page 2 of 5”
 *   plural('files', 20)                           → „20 files” / „20 de fișiere”
 *   rich('discover.rankingFor', { day: <time>…</time> }) → propoziția, cu elementul React în ea
 *
 * Componenta se redesenează singură când se schimbă limba (useSyncExternalStore, ca la temă).
 * Datele, numerele și duratele se formatează cu funcțiile din lib/format.ts, care urmează aceeași limbă.
 */
export function useI18n() {
    const locale = useSyncExternalStore(subscribeToLocale, getLocale);

    return { locale, t: translate, plural: pluralize, rich: translateRich };
}
