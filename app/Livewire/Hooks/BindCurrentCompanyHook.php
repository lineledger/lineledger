<?php

namespace App\Livewire\Hooks;

use App\Models\Company;
use Livewire\ComponentHook;

/**
 * Re-binds `current_company` in the container on every Livewire request.
 *
 * Initial GET requests bind it via the EnsureCompanyMembership HTTP middleware,
 * but Livewire's AJAX update endpoint (/livewire-a56fc212/update) does NOT
 * route through that middleware — it's a global Livewire route with only
 * RequireLivewireHeaders applied. Without re-binding, the BelongsToCompany
 * trait can't auto-fill company_id on new models and CompanyScope can't
 * scope queries to the current company.
 *
 * Any Livewire component with a `public Company $company` property gets
 * the binding for free. Multi-tab safe — each request's component carries
 * its own company in the snapshot.
 */
class BindCurrentCompanyHook extends ComponentHook
{
    public function mount($params, $parent): void
    {
        $this->bindFromComponent();
    }

    public function hydrate(): void
    {
        $this->bindFromComponent();
    }

    public function call($method, $params, $returnEarly, $metadata, $componentContext)
    {
        $this->bindFromComponent();
    }

    public function update($property, $path, $value)
    {
        $this->bindFromComponent();
    }

    public function render($view, $data)
    {
        $this->bindFromComponent();
    }

    protected function bindFromComponent(): void
    {
        $component = $this->component;

        if ($component === null) {
            return;
        }

        if (! property_exists($component, 'company') || ! isset($component->company)) {
            return;
        }

        if ($component->company instanceof Company) {
            app()->instance('current_company', $component->company);
        }
    }
}
