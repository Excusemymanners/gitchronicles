/*
 * Dicționarul englez: textele interfeței, grupate pe zone (common, nav, home, auth, discover…).
 * E dicționarul de referință: forma lui dă tipul `Dictionary`, pe care trebuie să îl respecte orice altă limbă
 * (resources/js/lang/ro.ts). O cheie se folosește cu calea ei: t('home.hero.title').
 *
 * Reguli:
 *  - {nume} e un loc pentru o valoare: t('auth.pending.login', { repository: '…' });
 *  - `counts` ține substantivele cu număr, câte o formă pentru fiecare categorie de plural a limbii
 *    (engleza: one, other); se folosesc cu plural('files', 20) și încep mereu cu {count};
 *  - textul se scrie ca pentru un produs în engleză, nu ca traducere a celui românesc.
 */
const en = {
    common: {
        appName: 'GitChronicle',
        opensInNewTab: '(opens in a new tab)',
        other: 'Other',
        unknown: 'unknown',
    },
    frontDoor: {
        title: 'Document this repository',
        lead: '{repository} is not in GitChronicle yet. Add it and we write its documentation, draw its diagrams and follow its history.',
        openOnGitHub: 'View on GitHub',
        facts: {
            stars: 'Stars',
            forks: 'Forks',
            language: 'Main language',
            license: 'License',
            lastPush: 'Last push',
            defaultBranch: 'Default branch',
        },
        action: 'Document this repository',
        submitting: 'Adding…',
        hintUser: 'We start right away, and the next page shows the progress.',
        hintGuest: 'Adding needs an account. Log in or sign up and we add it right after.',
        willDoTitle: 'What happens next',
        unavailable: {
            title: 'GitHub did not answer',
            text: 'We could not load the details of this repository from GitHub right now, so we show none. You can still add it: we read everything from the code itself.',
        },
        notFound: {
            title: 'We could not find {repository}',
            text: 'GitHub says this repository does not exist, or it is private. GitChronicle documents public repositories only, so check the spelling of the link.',
            home: 'Back to home',
            discover: 'Browse Discover',
        },
    },
    nav: {
        skipToContent: 'Skip to content',
        homeSuffix: ', home page',
        main: 'Main navigation',
        discover: 'Discover',
        logIn: 'Log in',
        logInShort: 'Log in',
        signUp: 'Sign up',
        logOut: 'Log out',
        adminTag: 'admin',
    },
    footer: {
        tagline: 'turns the code and git history of every repository into documentation.',
        thesis: 'Bachelor’s thesis project, 2026–2027',
    },
    theme: {
        toLight: 'Switch to light theme',
        toDark: 'Switch to dark theme',
    },
    language: {
        button: 'Language: {language}',
        change: 'Change language',
    },
    flash: {
        dismiss: 'Dismiss message',
    },
    format: {
        justNow: 'just now',
        createdToday: 'created today',
    },
    statuses: {
        pending: 'Queued',
        cloning: 'Cloning',
        analyzing: 'Analyzing code',
        documenting: 'Writing docs',
        ready: 'Ready',
        updating: 'Updating',
        failed: 'Failed',
    },
    pipeline: {
        done: '(done)',
        inProgress: '(in progress)',
        stages: {
            clone: {
                label: 'Clone',
                description: 'We fetch the repository from GitHub, with its full history.',
            },
            analyze: {
                label: 'Code analysis',
                description: 'We read every file: languages, modules, classes and functions, and what imports what.',
            },
            history: {
                label: 'Git history',
                description: 'We import the commits: authors, files touched, lines added and removed.',
            },
            docs: {
                label: 'Documentation',
                description: 'We write an “About the project” page, one page per main module and a README.',
            },
            diagrams: {
                label: 'Diagrams',
                description: 'We draw the architecture: layers, C4 views and the main flows.',
            },
            index: {
                label: 'Assistant index',
                description: 'We split the code into searchable chunks, so answers can cite exact lines.',
            },
            ready: {
                label: 'Ready',
                description: 'On every new commit we redo only what changed, never the whole project.',
            },
        },
    },
    home: {
        title: 'Home',
        metaDescription: 'Living documentation for your GitHub repositories, written from the code and its git history.',
        hero: {
            title: 'Documentation that keeps up with every commit.',
            lead: 'GitChronicle reads a repository’s code and git history, writes its documentation, draws its architecture and answers questions with links to the exact lines. When a new commit lands, it rewrites only what changed.',
        },
        how: {
            title: 'How it works',
            lead: 'Seven steps, always in the same order. Each one starts once the previous one succeeds.',
        },
        capabilities: {
            title: 'What you get for every repository',
            docs: {
                title: 'Documentation',
                text: 'An “About the project” overview, a page for each important module and a README. Every page names the commit it was written from.',
            },
            diagrams: {
                title: 'Architecture diagrams',
                text: 'Layers, C4 (context, containers, components) and flows, drawn from the real imports between modules.',
            },
            assistant: {
                title: 'Assistant with citations',
                text: 'Ask about the code in your own words. Every answer points to the files and lines it relies on.',
            },
            history: {
                title: 'History and time travel',
                text: 'Commits, the files that change most and the people behind them. Read the documentation as it was at any earlier commit.',
            },
            video: {
                title: 'Presentation video',
                text: 'A narrated vertical clip and a slide deck (PDF and PPTX), built from the repository’s own data.',
            },
        },
        repositories: {
            title: 'Repositories',
            emptyTitle: 'No repositories yet.',
            emptyText: 'Paste a GitHub link in the field above. Its card shows up here, and you can watch its documentation being written, step by step.',
            emptyAction: 'Go to the link field',
        },
        form: {
            label: 'Link to a public GitHub repository',
            placeholder: 'https://github.com/owner/name',
            submit: 'Add repository',
            submitting: 'Adding…',
            hintUser: 'If the repository is already here, we simply open its page.',
            hintGuest: 'Browsing needs no account. To add a new repository you log in first, and we add it right after.',
            replaceHost: 'Or replace github.com with {host} in any repository link.',
        },
        card: {
            failed: 'Processing stopped with an error.',
            updatesItself: 'This card updates itself until the repository is ready.',
            lastCommit: 'Last processed commit:',
            processed: 'processed',
            onlyStructure: 'Only the code structure so far.',
            docs: 'Docs',
            diagrams: 'Diagrams',
            assistant: 'Assistant',
            video: 'Video',
        },
    },
    auth: {
        pending: {
            login: 'As soon as you log in, we’ll add',
            register: 'As soon as your account is ready, we’ll add',
        },
        after: {
            title: 'What happens after you add a repository',
            lead: 'You can follow it step by step; its page updates itself.',
        },
        fields: {
            name: 'Name',
            email: 'Email',
            password: 'Password',
            passwordConfirmation: 'Confirm password',
            passwordHint: 'At least 8 characters.',
            remember: 'Remember me on this device',
        },
        login: {
            title: 'Log in',
            heading: 'Log in',
            lead: 'You only need an account to add repositories. The documentation, diagrams and assistant are open to everyone.',
            submit: 'Log in',
            submitting: 'Checking…',
            noAccount: 'No account yet?',
            createOne: 'Create one',
        },
        register: {
            title: 'Sign up',
            heading: 'Create an account',
            lead: 'With an account you can add public GitHub repositories and delete the ones you added.',
            submit: 'Create account',
            submitting: 'Creating your account…',
            haveAccount: 'Already have an account?',
            logIn: 'Log in',
        },
    },
    discover: {
        title: 'Discover',
        lead: 'Public GitHub repositories that are gaining stars the fastest. We find them through the GitHub Search API and record their stars once a day; growth is the difference between two snapshots.',
        empty: {
            title: 'No ranking data collected yet.',
            text: 'Run {refresh} (Laravel’s scheduler runs it every day: {schedule}).',
        },
        rankingFor: 'Ranking for {day}',
        period: 'Period',
        periods: {
            '24h': '24 hours',
            '7d': '7 days',
            '30d': '30 days',
        },
        language: 'Language',
        allLanguages: 'All languages',
        noEntries: 'No repositories in the ranking for {day}.',
        noEntriesInLanguage: 'No {language} repositories in the ranking for {day}.',
        rank: 'Rank',
        pagination: 'Pagination',
        previous: '← Previous',
        next: 'Next →',
        pageOf: 'Page {current} of {last}',
        repositoryOfTheDay: 'Repository of the day · {day}',
        figures: {
            language: 'Language',
            stars: 'Stars',
            growth: 'Growth over {period}',
            growthValue: '{value} stars',
            average: 'Average since creation',
            averageValue: '{value} stars/day',
            age: 'Age',
        },
        actions: {
            open: 'Open documentation',
            document: 'Document',
            documentLabel: 'Document {name}',
            adding: 'Adding…',
            guestHint: 'You’ll be asked to log in first; then we add it for you.',
        },
        ordering: {
            growth: 'Ordered by {emphasis}: the difference between the snapshots of {day} and {baseline}. Repositories missing either snapshot have “unknown” growth and come after the others.',
            growthEmphasis: 'stars gained in the last {period}',
            average: 'Growth over {period} can’t be measured yet: it also needs the snapshot of {baseline}{firstSnapshot}. Until then, the ranking is ordered by {emphasis}, an average over its whole life rather than recent growth.',
            firstSnapshot: ' (the first snapshot is from {day})',
            averageEmphasis: 'average stars per day since the repository was created',
        },
    },
    counts: {
        repositories: { one: '{count} repository', other: '{count} repositories' },
        files: { one: '{count} file', other: '{count} files' },
        filesAnalyzed: { one: '{count} file analyzed', other: '{count} files analyzed' },
        pagesWritten: { one: '{count} page written', other: '{count} pages written' },
        modules: { one: '{count} module', other: '{count} modules' },
        commits: { one: '{count} commit', other: '{count} commits' },
        pages: { one: '{count} page', other: '{count} pages' },
        diagrams: { one: '{count} diagram', other: '{count} diagrams' },
        days: { one: '{count} day', other: '{count} days' },
        months: { one: '{count} month', other: '{count} months' },
        years: { one: '{count} year', other: '{count} years' },
    },
};

export default en;
