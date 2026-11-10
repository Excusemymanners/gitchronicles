<?php

namespace App\Http\Controllers\Auth;

use App\Actions\AddRepository;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class LoginController extends Controller
{
    /**
     * Show the login form, with the repository the visitor asked to add as a guest (or null).
     *
     * The URL stays in the session: it is removed only after a successful login.
     */
    public function create(Request $request): Response
    {
        return Inertia::render('auth/login', [
            'pending_repository' => $request->session()->get(AddRepository::PENDING_URL_SESSION_KEY),
        ]);
    }

    /**
     * Log the user in. If they asked to add a repository as a guest, add it now and open its page;
     * otherwise go to the page they wanted to open (or to the home page).
     */
    public function store(LoginRequest $request, AddRepository $addRepository): RedirectResponse
    {
        $request->authenticate();

        $request->session()->regenerate();

        $repository = $addRepository->completePending($request);

        if ($repository !== null) {
            return redirect()->route('repositories.show', $repository);
        }

        return redirect()->intended(route('home'));
    }

    /**
     * Log the user out and throw away their session and CSRF token.
     */
    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }
}
