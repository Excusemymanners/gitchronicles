/** O perioadă a clasamentului, ca în adresă (?period=7d); numele ei e în dicționar, la „discover.periods”. */
export type DiscoverPeriodOption = '24h' | '7d' | '30d';

/** Un repository din clasament (DiscoverController::entry în Laravel). Toate cifrele vin din notările zilnice. */
export type DiscoverEntry = {
    /** Locul în clasament, de la 1. */
    position: number;
    id: number;
    /** „proprietar/nume”, scris ca pe GitHub. */
    full_name: string;
    github_url: string;
    description: string | null;
    /** Limbajul principal, după GitHub; `null` dacă GitHub nu știe. */
    language: string | null;
    /** Stelele din ziua clasamentului. */
    stars: number;
    /** Stelele câștigate în perioada aleasă; `null` = necunoscut (lipsește una dintre cele două notări), niciodată 0 inventat. */
    growth: number | null;
    /** Media de stele pe zi de la crearea repository-ului: stele ÷ vârsta în zile. */
    average_per_day: number;
    /** Zile întregi de la crearea pe GitHub. */
    age_days: number;
    /** Linkul trimis la POST /repositories de butonul „Documentează”. */
    add_url: string;
    /** Pagina din GitChronicle, când repository-ul e deja documentat; `null` altfel. */
    documentation_url: string | null;
};

/** Ziua clasamentului și după ce e ordonat. */
export type DiscoverRanking = {
    /** Ziua ultimei notări („2026-11-11”); `null` cât timp nu s-a adunat nimic. */
    day: string | null;
    /** Ziua cu care se compară: ziua clasamentului minus perioada. */
    baseline_day: string | null;
    /** Ziua primei notări. */
    first_day: string | null;
    /** „growth” = după creșterea măsurată; „average” = după media de la creare, cât timp creșterea nu se poate măsura. */
    ordered_by: 'growth' | 'average';
    /** Câte repository-uri are clasamentul, cu filtrele alese. */
    total: number;
};

export type DiscoverPagination = {
    current_page: number;
    last_page: number;
    previous_url: string | null;
    next_url: string | null;
};
