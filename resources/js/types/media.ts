/** Starea video-ului sau a slide-urilor: în coadă, în lucru, gata sau eșuat (MediaStatus în Laravel). */
export type MediaStatus = 'pending' | 'running' | 'ready' | 'failed';

/** De unde vine textul unei scene: sursa (de exemplu „README.md”), citatul exact și adresa sursei, dacă are una. */
export type SceneSource = {
    label: string;
    quote: string;
    url: string | null;
};

/** O scenă a video-ului: când se vede, ce scrie pe ecran, ce spune vocea și sursa textului. */
export type VideoScene = {
    /** Tipul scenei: hook, problem, feature, built, proof, start, cta. */
    key: string;
    /** Numele scenei în limba video-ului, de exemplu „The problem” sau „Ce rezolvă”. */
    label: string;
    start_ms: number;
    end_ms: number;
    title: string | null;
    text: string | null;
    /** Comanda afișată în scena „Cum începi”. */
    command: string | null;
    narration: string | null;
    /** `null` pentru o scenă făcută doar din cifre. */
    source: SceneSource | null;
};

/** Ce au în comun video-ul și slide-urile (PresentationMediaResource::state în Laravel). */
type MediaState = {
    status: MediaStatus;
    /** Starea tradusă în română, gata de afișat. */
    status_label: string;
    /** Ce n-a mers, pe înțelesul oricui; există doar când starea e „failed”. */
    error: string | null;
    /** Data generării, în format ISO 8601; `null` până e gata prima dată. */
    generated_at: string | null;
    size_bytes: number | null;
    /** Adevărat când repository-ul are un commit mai nou decât cel din care s-a generat. */
    is_stale: boolean;
};

/** Limba unui video: codul din MediaLanguage (Laravel). */
export type MediaLanguage = 'en' | 'ro';

/** Video-ul de prezentare într-o limbă. Adresele fișierelor sunt `null` cât timp video-ul nu e gata. */
export type PresentationVideo = MediaState & {
    language: MediaLanguage;
    /** Numele limbii, în limba ei: „English”, „Română”. */
    language_label: string;
    duration_ms: number | null;
    src: string | null;
    poster: string | null;
    /** Subtitrările în format WebVTT. */
    captions: string | null;
    download: string | null;
    /** „ai” = textele scrise de Claude din sursele repository-ului; „template” = construite fără AI. */
    writer: 'ai' | 'template' | null;
    /** De ce s-a folosit varianta fără AI, când e cazul. */
    note: string | null;
    /** Vocea care citește textele; `null` = video fără voce. */
    voice: string | null;
    /** De ce pagina de GitHub nu a putut fi fotografiată (video-ul are atunci scene tipografice); `null` = a fost. */
    capture_note: string | null;
    scenes: VideoScene[];
};

/** Slide-urile: fișierele de descărcat și titlul fiecărui slide. */
export type PresentationSlides = MediaState & {
    downloads: { pdf: string | null; pptx: string | null };
    titles: string[];
};

/** Prop-ul „media” al paginii „Video” (PresentationMediaResource în Laravel). */
export type PresentationMedia = {
    /** Câte un video pentru fiecare limbă pornită vreodată, limba implicită prima; gol până la prima generare. */
    videos: PresentationVideo[];
    /** Limba arătată prima. */
    default_language: MediaLanguage;
    slides: PresentationSlides | null;
    /** Adevărat cât timp ceva e în coadă sau în lucru: pagina se reîmprospătează singură. */
    is_working: boolean;
    /** Adevărat când vizitatorul e autentificat și poate porni o generare (RepositoryPolicy::generateMedia). */
    can_generate: boolean;
};
