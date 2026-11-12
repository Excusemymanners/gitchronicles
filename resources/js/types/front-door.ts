/** Ce spune GitHub despre un repository încă nedocumentat (RepositoryLookup în Laravel); toate sunt date reale. */
export type GitHubFacts = {
    /** „proprietar/nume”, scris cum îl scrie GitHub. */
    full_name: string;
    description: string | null;
    /** Avatarul proprietarului, de pe avatars.githubusercontent.com. */
    avatar_url: string | null;
    stars: number;
    forks: number;
    language: string | null;
    /** Identificatorul SPDX, de exemplu „MIT”; `null` când GitHub nu l-a recunoscut. */
    license: string | null;
    /** Data ISO 8601 a ultimului push. */
    pushed_at: string | null;
    default_branch: string | null;
};
