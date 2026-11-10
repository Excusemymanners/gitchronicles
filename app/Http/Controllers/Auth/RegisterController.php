<?php

namespace App\Http\Controllers\Auth;

use App\Actions\AddRepository;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class RegisterController extends Controller
{
    /**
     * Show the registration form, with the repository the visitor asked to add as a guest (or null).
     *
     * The URL stays in the session: it is removed only after the account is created.
     */
    public function create(Request $request): Response
    {
        return Inertia::render('auth/register', [
            'pending_repository' => $request->session()->get(AddRepository::PENDING_URL_SESSION_KEY),
        ]);
    }

    /**
     * Create the account and log the new user in. If they asked to add a repository as a guest,
     * add it now and open its page; otherwise go to the home page.
     */
    public function store(RegisterRequest $request, AddRepository $addRepository): RedirectResponse
    {
        $user = User::create($request->validated());

        Auth::login($user);

        $request->session()->regenerate();

        $repository = $addRepository->completePending($request);

        if ($repository !== null) {
            return redirect()->route('repositories.show', $repository);
        }

        return redirect()->route('home');
    }
}
