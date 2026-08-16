<?php

namespace App\Http\Responses\Concerns;

use Illuminate\Support\Facades\URL;

trait RedirectsToCurrentCompany
{
    protected function redirectPathForCurrentCompany($request, string $redirect): string
    {
        $company = $this->currentCompany($request);

        if (! $company) {
            return route('welcome.create-company', absolute: false);
        }

        URL::defaults(['company' => $company->slug]);

        return "/{$company->slug}{$redirect}";
    }

    protected function currentCompany($request)
    {
        $user = $request->user();

        if (! $user) {
            return null;
        }

        return $user->currentCompany ?? $user->personalCompany();
    }
}
