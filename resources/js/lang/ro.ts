import type { Dictionary } from '@/lang';

/*
 * Dicționarul românesc: aceleași chei ca dicționarul englez (en.ts). Tipul `Dictionary` face ca o cheie lipsă
 * sau în plus să fie o eroare de TypeScript (npx tsc --noEmit).
 *
 * Pluralul românesc are trei forme (categoriile din Intl.PluralRules('ro')):
 * one = 1 („1 fișier”), few = 0 și 2–19, plus 101–119 etc. („5 fișiere”), other = 20 în sus („20 de fișiere”).
 */
const ro: Dictionary = {
    common: {
        appName: 'GitChronicle',
        opensInNewTab: '(se deschide într-o filă nouă)',
        other: 'Altele',
        unknown: 'necunoscut',
    },
    frontDoor: {
        title: 'Documentează acest repository',
        lead: '{repository} nu este încă în GitChronicle. Adaugă-l și îi scriem documentația, îi desenăm diagramele și îi urmărim istoricul.',
        openOnGitHub: 'Vezi pe GitHub',
        facts: {
            stars: 'Stele',
            forks: 'Fork-uri',
            language: 'Limbaj principal',
            license: 'Licență',
            lastPush: 'Ultimul push',
            defaultBranch: 'Ramura principală',
        },
        action: 'Documentează acest repository',
        submitting: 'Se adaugă…',
        hintUser: 'Pornim imediat, iar pagina următoare arată progresul.',
        hintGuest: 'Adăugarea cere un cont. Intră în cont sau creează unul și îl adăugăm imediat după.',
        willDoTitle: 'Ce urmează',
        unavailable: {
            title: 'GitHub nu a răspuns',
            text: 'Nu am putut încărca acum de pe GitHub detaliile acestui repository, așa că nu afișăm niciunul. Îl poți adăuga oricum: citim totul din codul lui.',
        },
        notFound: {
            title: 'Nu am găsit {repository}',
            text: 'GitHub spune că repository-ul nu există sau este privat. GitChronicle documentează doar repository-uri publice, așa că verifică scrierea linkului.',
            home: 'Înapoi la prima pagină',
            discover: 'Vezi Descoperă',
        },
    },
    nav: {
        skipToContent: 'Sari la conținut',
        homeSuffix: ', prima pagină',
        main: 'Navigare principală',
        discover: 'Descoperă',
        logIn: 'Autentificare',
        logInShort: 'Intră',
        signUp: 'Cont nou',
        logOut: 'Ieșire',
        adminTag: 'admin',
    },
    footer: {
        tagline: 'scrie documentația din codul și din istoricul git al fiecărui repository.',
        thesis: 'Proiect de licență, 2026–2027',
    },
    theme: {
        toLight: 'Temă luminoasă',
        toDark: 'Temă întunecată',
    },
    language: {
        button: 'Limba: {language}',
        change: 'Schimbă limba',
    },
    flash: {
        dismiss: 'Închide mesajul',
    },
    format: {
        justNow: 'chiar acum',
        createdToday: 'creat azi',
    },
    statuses: {
        pending: 'În așteptare',
        cloning: 'Se clonează',
        analyzing: 'Se analizează codul',
        documenting: 'Se scrie documentația',
        ready: 'Gata',
        updating: 'Se actualizează',
        failed: 'Eroare',
    },
    pipeline: {
        done: '(făcut)',
        inProgress: '(în lucru)',
        stages: {
            clone: {
                label: 'Clonare',
                description: 'Aducem repository-ul de pe GitHub, cu tot istoricul lui.',
            },
            analyze: {
                label: 'Analiza codului',
                description: 'Citim fiecare fișier: limbaje, module, clase și funcții, cine importă pe cine.',
            },
            history: {
                label: 'Istoricul git',
                description: 'Importăm commit-urile: autori, fișiere atinse, linii adăugate și șterse.',
            },
            docs: {
                label: 'Documentație',
                description: 'Scriem „Despre proiect”, câte o pagină pentru modulele principale și un README.',
            },
            diagrams: {
                label: 'Diagrame',
                description: 'Desenăm arhitectura: straturi, C4 și fluxurile principale.',
            },
            index: {
                label: 'Index pentru asistent',
                description: 'Împărțim codul în fragmente căutabile, ca răspunsurile să citeze linii exacte.',
            },
            ready: {
                label: 'Gata',
                description: 'La un commit nou refacem doar ce s-a schimbat, nu tot proiectul.',
            },
        },
    },
    home: {
        title: 'Acasă',
        metaDescription: 'Documentație vie pentru repository-urile tale',
        hero: {
            title: 'Documentație care ține pasul cu fiecare commit.',
            lead: 'GitChronicle citește codul și istoricul git al unui repository, îi scrie documentația, îi desenează arhitectura și răspunde la întrebări cu trimiteri la liniile exacte. La următorul commit reface doar ce s-a schimbat.',
        },
        how: {
            title: 'Cum funcționează',
            lead: 'Șapte pași, mereu în aceeași ordine. Fiecare pornește după ce a reușit cel dinainte.',
        },
        capabilities: {
            title: 'Ce găsești în fiecare repository',
            docs: {
                title: 'Documentație',
                text: '„Despre proiect”, o pagină pentru fiecare modul important și un README. Fiecare pagină spune din ce commit a fost scrisă.',
            },
            diagrams: {
                title: 'Diagrame de arhitectură',
                text: 'Straturi, C4 (context, containere, componente) și fluxuri, desenate din importurile reale dintre module.',
            },
            assistant: {
                title: 'Asistent cu citări',
                text: 'Întrebi despre cod în cuvintele tale. Răspunsul trimite la fișierul și la liniile pe care se sprijină.',
            },
            history: {
                title: 'Istoric și călătorie în timp',
                text: 'Commit-urile, fișierele schimbate cel mai des și contribuitorii. Poți vedea documentația așa cum era la un commit mai vechi.',
            },
            video: {
                title: 'Video de prezentare',
                text: 'Un clip vertical cu voce și un set de slide-uri (PDF și PPTX), făcute din datele repository-ului.',
            },
        },
        repositories: {
            title: 'Repository-uri',
            emptyTitle: 'Încă nu a fost adăugat niciun repository.',
            emptyText: 'Lipește un link de GitHub în câmpul de sus. Aici apare cardul lui și poți urmări, pas cu pas, cum i se scrie documentația.',
            emptyAction: 'Mergi la câmpul pentru link',
        },
        form: {
            label: 'Linkul unui repository public de pe GitHub',
            placeholder: 'https://github.com/proprietar/nume',
            submit: 'Adaugă repository',
            submitting: 'Se adaugă…',
            hintUser: 'Dacă repository-ul a fost deja adăugat, îi deschidem direct pagina.',
            hintGuest: 'Poți răsfoi totul fără cont. Pentru un repository nou îți cerem să intri în cont, apoi îl adăugăm automat.',
            replaceHost: 'Sau înlocuiește github.com cu {host} în orice link de repository.',
        },
        card: {
            failed: 'Procesarea s-a oprit cu o eroare.',
            updatesItself: 'Cardul se actualizează singur până la „Gata”.',
            lastCommit: 'Ultimul commit procesat:',
            processed: 'procesat',
            onlyStructure: 'Deocamdată are doar structura codului.',
            docs: 'Documentație',
            diagrams: 'Diagrame',
            assistant: 'Asistent',
            video: 'Video',
        },
    },
    auth: {
        pending: {
            login: 'După ce intri în cont, adăugăm automat',
            register: 'După ce îți creezi contul, adăugăm automat',
        },
        after: {
            title: 'Ce se întâmplă după ce adaugi un repository',
            lead: 'Îl poți urmări pas cu pas; pagina lui se actualizează singură.',
        },
        fields: {
            name: 'Nume',
            email: 'Email',
            password: 'Parolă',
            passwordConfirmation: 'Confirmă parola',
            passwordHint: 'Cel puțin 8 caractere.',
            remember: 'Ține-mă minte pe acest dispozitiv',
        },
        login: {
            title: 'Autentificare',
            heading: 'Intră în cont',
            lead: 'Contul e necesar doar ca să adaugi repository-uri. Documentația, diagramele și asistentul se pot folosi fără el.',
            submit: 'Intră în cont',
            submitting: 'Se verifică…',
            noAccount: 'Nu ai cont?',
            createOne: 'Creează unul',
        },
        register: {
            title: 'Cont nou',
            heading: 'Creează un cont',
            lead: 'Cu un cont poți adăuga repository-uri publice de pe GitHub și le poți șterge pe cele adăugate de tine.',
            submit: 'Creează cont',
            submitting: 'Se creează contul…',
            haveAccount: 'Ai deja cont?',
            logIn: 'Intră în cont',
        },
    },
    discover: {
        title: 'Descoperă',
        lead: 'Repository-uri publice de pe GitHub care câștigă stele cel mai repede. Le găsim cu GitHub Search API și le notăm stelele o dată pe zi; creșterea e diferența dintre două notări.',
        empty: {
            title: 'Încă nu am adunat date pentru clasament.',
            text: 'Rulează {refresh} (zilnic îl pornește Laravel Scheduler: {schedule}).',
        },
        rankingFor: 'Clasamentul din {day}',
        period: 'Perioada',
        periods: {
            '24h': '24 de ore',
            '7d': '7 zile',
            '30d': '30 de zile',
        },
        language: 'Limbaj',
        allLanguages: 'Toate limbajele',
        noEntries: 'Niciun repository în clasamentul din {day}.',
        noEntriesInLanguage: 'Niciun repository în {language} în clasamentul din {day}.',
        rank: 'Locul',
        pagination: 'Paginare',
        previous: '← Anterioara',
        next: 'Următoarea →',
        pageOf: 'Pagina {current} din {last}',
        repositoryOfTheDay: 'Repo-ul zilei · {day}',
        figures: {
            language: 'Limbaj',
            stars: 'Stele',
            growth: 'Creștere în {period}',
            growthValue: '{value} stele',
            average: 'Medie de la creare',
            averageValue: '{value} stele/zi',
            age: 'Vârstă',
        },
        actions: {
            open: 'Deschide documentația',
            document: 'Documentează',
            documentLabel: 'Documentează {name}',
            adding: 'Se adaugă…',
            guestHint: 'Îți cerem să intri în cont, apoi îl adăugăm automat.',
        },
        ordering: {
            growth: 'Ordonat după {emphasis}: diferența dintre notarea din {day} și cea din {baseline}. Repository-urile fără ambele notări au creșterea „necunoscut” și stau după celelalte.',
            growthEmphasis: 'stelele câștigate în ultimele {period}',
            average: 'Creșterea pe {period} nu se poate măsura încă: e nevoie și de notarea din {baseline}{firstSnapshot}. Până atunci clasamentul e ordonat după {emphasis} — o medie pe toată viața lui, nu o creștere recentă.',
            firstSnapshot: ' (prima notare e din {day})',
            averageEmphasis: 'media de stele pe zi de la crearea repository-ului',
        },
    },
    counts: {
        repositories: { one: '{count} repository', few: '{count} repository-uri', other: '{count} de repository-uri' },
        files: { one: '{count} fișier', few: '{count} fișiere', other: '{count} de fișiere' },
        filesAnalyzed: { one: '{count} fișier analizat', few: '{count} fișiere analizate', other: '{count} de fișiere analizate' },
        pagesWritten: { one: '{count} pagină scrisă', few: '{count} pagini scrise', other: '{count} de pagini scrise' },
        modules: { one: '{count} modul', few: '{count} module', other: '{count} de module' },
        commits: { one: '{count} commit', few: '{count} commit-uri', other: '{count} de commit-uri' },
        pages: { one: '{count} pagină', few: '{count} pagini', other: '{count} de pagini' },
        diagrams: { one: '{count} diagramă', few: '{count} diagrame', other: '{count} de diagrame' },
        days: { one: '{count} zi', few: '{count} zile', other: '{count} de zile' },
        months: { one: '{count} lună', few: '{count} luni', other: '{count} de luni' },
        years: { one: '{count} an', few: '{count} ani', other: '{count} de ani' },
    },
};

export default ro;
