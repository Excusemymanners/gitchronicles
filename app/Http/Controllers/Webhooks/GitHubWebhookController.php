<?php

namespace App\Http\Controllers\Webhooks;

use App\Actions\UpdateRepository;
use App\Enums\ProcessingTrigger;
use App\Enums\RepositoryStatus;
use App\Http\Controllers\Controller;
use App\Models\Repository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GitHub's webhook (POST /webhooks/github): after every push to a repository that has the webhook
 * configured, GitHub calls this address, and the repository gets an incremental update at once,
 * without waiting for the scheduled check (Faza 9).
 *
 * Anyone on the internet can call this address, so nothing is believed until the signature is checked:
 * GitHub signs the exact bytes of the request with the secret typed in the webhook's settings
 * (HMAC-SHA256, the "X-Hub-Signature-256: sha256=…" header), and we compute the same signature with
 * our copy of the secret (GITHUB_WEBHOOK_SECRET). Without a configured secret the address answers 404.
 *
 * The answers: 200 for GitHub's "ping" (sent when the webhook is created), 202 "queued" for a push to
 * the main branch of a repository we know, 202 "ignored" for anything else that is correctly signed
 * (another event, another branch, a repository that was not added to GitChronicle, one that is not
 * "ready", or a push that came less than the minimum interval after the last update, see
 * UpdateRepository), 401 for a missing or wrong signature. The address is rate-limited ("webhooks").
 *
 * Like the scheduled check, a push changes nothing for a repository that is being processed or has
 * failed: the first is already working (the next check picks up the new commit), the second waits for
 * someone to press „Reîncearcă”.
 */
class GitHubWebhookController extends Controller
{
    /**
     * Handle one delivery from GitHub.
     */
    public function __invoke(Request $request, UpdateRepository $updateRepository): JsonResponse
    {
        $secret = config('services.github.webhook_secret');

        abort_if(blank($secret), 404);

        if (! $this->isSignedWith($request, $secret)) {
            return response()->json(['status' => 'invalid signature'], 401);
        }

        return match ($request->header('X-GitHub-Event')) {
            'ping' => response()->json(['status' => 'pong']),
            'push' => $this->push($request, $updateRepository),
            default => $this->ignored('not a push'),
        };
    }

    /**
     * Whether the request carries the signature GitHub would compute for this body with our secret.
     * hash_equals compares in constant time, so the answer's timing says nothing about the secret.
     */
    private function isSignedWith(Request $request, string $secret): bool
    {
        $signature = $request->header('X-Hub-Signature-256');

        return is_string($signature)
            && hash_equals('sha256='.hash_hmac('sha256', $request->getContent(), $secret), $signature);
    }

    /**
     * A push: start an update when it is a push of commits to the main branch of a repository that
     * was added to GitChronicle. The repository is found by its "owner/name" (GitHub ignores the case).
     */
    private function push(Request $request, UpdateRepository $updateRepository): JsonResponse
    {
        $payload = $this->payload($request);
        $fullName = $payload['repository']['full_name'] ?? null;

        if (! is_string($fullName) || substr_count($fullName, '/') !== 1) {
            return $this->ignored('no repository');
        }

        [$owner, $name] = explode('/', strtolower($fullName));

        $repository = Repository::query()
            ->whereRaw('lower(owner) = ?', [$owner])
            ->whereRaw('lower(name) = ?', [$name])
            ->first();

        if ($repository === null) {
            return $this->ignored('unknown repository');
        }

        // GitHub names the main branch in every push (signed, and right even after a rename on GitHub);
        // it is also the branch the pipeline fetches (origin's HEAD). Ours is only the fallback.
        $mainBranch = $payload['repository']['default_branch'] ?? $repository->default_branch;

        if (($payload['ref'] ?? null) !== "refs/heads/{$mainBranch}" || ($payload['deleted'] ?? false) === true) {
            return $this->ignored('not the main branch');
        }

        if ($repository->isBeingProcessed()) {
            return $this->ignored('already processing');
        }

        if ($repository->status !== RepositoryStatus::Ready) {
            return $this->ignored("repository is {$repository->status->value}");
        }

        if (! $updateRepository->handle($repository, ProcessingTrigger::Webhook)) {
            return $this->ignored('too soon after the last update');
        }

        return response()->json(['status' => 'queued'], 202);
    }

    /**
     * The data of the delivery. GitHub sends it as the JSON body (content type "application/json", the
     * setting the README recommends) or, with the content type "application/x-www-form-urlencoded",
     * as JSON inside the form field "payload". The signature covers the raw body either way.
     *
     * @return array<string, mixed>
     */
    private function payload(Request $request): array
    {
        $payload = $request->isJson() ? $request->json()->all() : json_decode((string) $request->input('payload'), true);

        return is_array($payload) ? $payload : [];
    }

    /**
     * A correctly signed delivery we have nothing to do with: accepted, so GitHub does not retry it.
     */
    private function ignored(string $reason): JsonResponse
    {
        return response()->json(['status' => 'ignored', 'reason' => $reason], 202);
    }
}
