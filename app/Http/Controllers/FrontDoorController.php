<?php

namespace App\Http\Controllers;

use App\FrontDoor\RepositoryLookup;
use App\Models\Repository;
use App\Support\GitHubUrl;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * The front door: https://github.com/laravel/framework becomes https://{our host}/laravel/framework.
 *
 * - a repository we already document: redirect to its page;
 * - an unknown one that exists on GitHub: the „Document this repository” page, with GitHub's real facts
 *   and one action that posts to the normal add flow (POST /repositories: a guest logs in first, then it is
 *   added). A GET never creates anything, because adding stays behind login (it is the cost control);
 * - one that does not exist (or is private): a 404 page; GitHub not answering: the same page without facts.
 *
 * The route is registered last (routes/web.php) and RESERVED_SEGMENTS keeps every other top-level path out of it.
 */
class FrontDoorController extends Controller
{
    /**
     * First path segments that belong to the app, the framework or the dev tools, never to a GitHub owner.
     * A test fails when a route is added under a first segment that is not listed here.
     *
     * @var list<string>
     */
    public const RESERVED_SEGMENTS = [
        // The app's own top-level routes.
        'discover', 'locale', 'login', 'logout', 'register', 'repositories', 'webhooks',
        // Framework, public folder and dev tools.
        '_boost', '_inertia', '_ignition', 'build', 'storage', 'up', 'sanctum', 'horizon', 'telescope', 'pulse', 'livewire',
        // Names GitHub itself never gives to an account, kept free for the product's own pages.
        'api', 'admin', 'assets', 'settings', 'dashboard', 'account', 'about', 'pricing', 'terms', 'privacy', 'help',
    ];

    /**
     * GitHub's rules for the two parts of the address, as route constraints (see GitHubUrl::PATTERN).
     */
    public const NAME_PATTERN = '(?!\.{1,2}(?:/|$))[A-Za-z0-9._-]{1,105}';

    /**
     * The owner: GitHub's username rules, and not one of the reserved first segments.
     */
    public static function ownerPattern(): string
    {
        $reserved = implode('|', array_map('preg_quote', self::RESERVED_SEGMENTS));

        return '(?!(?:'.$reserved.')(?:/|$))[A-Za-z0-9](?:[A-Za-z0-9]|-(?=[A-Za-z0-9])){0,38}';
    }

    /**
     * Resolve the pasted address. "/owner/name/tree/main/src" (the rest) and a ".git" suffix point to the same
     * repository as "/owner/name"; the match with our repositories ignores case (GitHubUrl lowercases).
     */
    public function __invoke(Request $request, RepositoryLookup $lookup, string $owner, string $name, ?string $rest = null): Response
    {
        $url = GitHubUrl::normalize("https://github.com/{$owner}/".preg_replace('/\.git$/i', '', $name));
        $parts = GitHubUrl::parse($url);

        abort_if($parts === null, 404);

        $repository = Repository::firstWhere('url', $url);

        if ($repository !== null) {
            return redirect()->route('repositories.show', $repository);
        }

        $answer = $lookup->find($parts['owner'], $parts['name']);

        if ($answer['state'] === RepositoryLookup::MISSING) {
            return $this->noindex(Inertia::render('front-door/not-found', [
                'full_name' => "{$parts['owner']}/{$parts['name']}",
            ])->toResponse($request)->setStatusCode(404));
        }

        // GitHub spells the name its own way and follows renames: add what it says, not what was typed.
        $facts = $answer['facts'];
        $addUrl = $facts === null ? $url : GitHubUrl::normalize("https://github.com/{$facts['full_name']}");

        return $this->noindex(Inertia::render('front-door/document', [
            'full_name' => $facts['full_name'] ?? "{$parts['owner']}/{$parts['name']}",
            'url' => $addUrl,
            'facts' => $facts,
        ])->toResponse($request));
    }

    /**
     * Keep these pages out of search engines: they exist for visitors, not for an index of every GitHub repository.
     */
    private function noindex(Response $response): Response
    {
        $response->headers->set('X-Robots-Tag', 'noindex');

        return $response;
    }
}
