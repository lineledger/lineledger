<?php

namespace App\Support\Legal;

use App\Enums\Country;
use App\Models\LegalAcceptance;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Reads the legal-document registry (config/legal.php) and the user's recorded
 * acceptances, and answers the questions the rest of the app asks: which
 * documents exist, which require acceptance, which a given user still owes, and
 * the public URL for each. Resolve from the container: app(LegalDocuments::class).
 *
 * @phpstan-type LegalDocument array{key: string, title: mixed, slug: mixed, version: mixed, requires_acceptance: bool, url: string}
 */
class LegalDocuments
{
    /**
     * Every registered document, each as an array with its key merged in:
     * ['key', 'title', 'slug', 'version', 'requires_acceptance', 'url'].
     *
     * @return Collection<string, LegalDocument>
     */
    public function all(): Collection
    {
        /** @var Collection<string, array<string, mixed>> $documents */
        $documents = collect(config('legal.documents', []));

        return $documents->map(fn (array $doc, string $key): array => [
            'key' => $key,
            'title' => $doc['title'],
            'slug' => $doc['slug'],
            'version' => $doc['version'],
            'requires_acceptance' => (bool) ($doc['requires_acceptance'] ?? false),
            'url' => $this->url($key),
        ]);
    }

    /**
     * The documents a user must explicitly agree to (and re-agree on change).
     *
     * @return Collection<string, LegalDocument>
     */
    public function requiring(): Collection
    {
        return $this->all()->filter(function (array $doc): bool {
            $requiresAcceptance = $doc['requires_acceptance'];

            return $requiresAcceptance;
        });
    }

    /**
     * The required documents whose *current* version the given user has not yet
     * accepted — i.e. brand-new (no row) or stale (a newer version shipped).
     *
     * @return Collection<string, LegalDocument>
     */
    public function outstanding(User $user): Collection
    {
        $accepted = $user->legalAcceptances()
            ->get(['document_key', 'version'])
            ->map(fn (LegalAcceptance $a): string => $a->document_key.'@'.$a->version)
            ->all();

        return $this->requiring()->reject(
            fn (array $doc): bool => in_array($doc['key'].'@'.$doc['version'], $accepted, true),
        );
    }

    public function hasOutstanding(User $user): bool
    {
        return $this->outstanding($user)->isNotEmpty();
    }

    /**
     * Record acceptance of the current version of each given document key. Safe
     * to call repeatedly: re-accepting a version already on file is a no-op.
     *
     * @param  array<int, string>  $keys
     */
    public function record(User $user, array $keys, ?Request $request = null): void
    {
        $documents = config('legal.documents', []);

        foreach ($keys as $key) {
            if (! isset($documents[$key])) {
                continue;
            }

            $user->legalAcceptances()->firstOrCreate(
                [
                    'document_key' => $key,
                    'version' => $documents[$key]['version'],
                ],
                [
                    'accepted_at' => now(),
                    'ip_address' => $request?->ip(),
                    'user_agent' => $request ? mb_substr((string) $request->userAgent(), 0, 255) : null,
                ],
            );
        }
    }

    /**
     * The user's most recent acceptance of a document (any version), or null if
     * they have never accepted it. Used to show "agreed on" dates.
     */
    public function latestAcceptance(User $user, string $key): ?LegalAcceptance
    {
        return $user->legalAcceptances()
            ->where('document_key', $key)
            ->latest('accepted_at')
            ->first();
    }

    /**
     * Absolute URL to a document on the region-appropriate marketing site.
     */
    public function url(string $key): string
    {
        $slug = config("legal.documents.{$key}.slug", '/legal');

        return rtrim($this->marketingBaseUrl(), '/').$slug;
    }

    /**
     * The marketing base URL for this deployment's region: an explicit
     * APP_REGION wins, otherwise it's derived from the request host — the same
     * resolution used by the guest country-switcher banner.
     */
    public function marketingBaseUrl(): string
    {
        $region = Country::tryFrom(mb_strtoupper((string) config('app.region')))
            ?? Country::fromHost(request()->getHost());

        $urls = config('app.marketing_urls', []);

        return $urls[$region->value] ?? $urls[Country::Canada->value] ?? 'https://lineledger.ca';
    }
}
