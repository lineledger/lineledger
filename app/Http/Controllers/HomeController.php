<?php

namespace App\Http\Controllers;

use App\Http\Responses\Concerns\RedirectsToCurrentCompany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Laravel\Fortify\Fortify;

class HomeController extends Controller
{
    use RedirectsToCurrentCompany;

    public function __invoke(Request $request): RedirectResponse
    {
        if (! $request->user()) {
            return redirect()->route('login');
        }

        return redirect($this->redirectPathForCurrentCompany($request, Fortify::redirects('login')));
    }
}
