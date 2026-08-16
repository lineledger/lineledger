<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Services\Reporting\Render\ReportRenderer;
use App\Support\Reporting\RenderableReports;
use App\Support\Reporting\ReportCatalog;
use App\Support\Reporting\ReportSettings;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Livewire\Attributes\Url;
use Livewire\Livewire;
use ReflectionNamedType;
use ReflectionObject;

/**
 * Opens a report as an inline PDF so the browser's viewer (and its print
 * dialog) takes over — the report pages' own exports always force a download.
 *
 * The report's current view state arrives as the same query string the page
 * itself uses (every filter is #[Url]-bound), so the print button can simply
 * forward window.location.search.
 */
class PrintReportController extends Controller
{
    public function __invoke(Request $request, Company $company, string $reportKey, ReportRenderer $renderer): Response
    {
        abort_unless(RenderableReports::supports($reportKey, 'pdf'), 404);

        // Mirror the hub's gating: a report hidden from this company/user
        // (feature flag, jurisdiction, role) cannot be printed either.
        $catalog = ReportCatalog::flatten($company, $request->user());
        abort_unless(array_key_exists($reportKey, $catalog), 404);

        $entry = RenderableReports::get($reportKey);
        $settings = $this->settingsFromQuery($request, $entry['component']);

        $artifact = $renderer->render($company, $reportKey, $settings, 'pdf');

        return new Response($artifact->bytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$artifact->filename.'"',
        ]);
    }

    /**
     * Map the page's query string onto a settings array by reading the
     * component's own #[Url] aliases, coercing values to the property types
     * (query params are strings; "false" must not become true).
     *
     * @return array<string, mixed>
     */
    private function settingsFromQuery(Request $request, string $componentAlias): array
    {
        $component = Livewire::new($componentAlias);
        $settings = [];

        foreach ((new ReflectionObject($component))->getProperties() as $property) {
            if (! in_array($property->getName(), ReportSettings::KEYS, true)) {
                continue;
            }

            foreach ($property->getAttributes(Url::class) as $attribute) {
                $alias = $attribute->newInstance()->as ?? $property->getName();

                if (! $request->query->has($alias)) {
                    continue;
                }

                $settings[$property->getName()] = $this->coerce(
                    $request->query($alias),
                    $property->getType(),
                );
            }
        }

        return $settings;
    }

    private function coerce(mixed $value, ?\ReflectionType $type): mixed
    {
        if (! $type instanceof ReflectionNamedType) {
            return $value;
        }

        if ($value === '' && $type->allowsNull()) {
            return null;
        }

        return match ($type->getName()) {
            'int' => (int) $value,
            'bool' => filter_var($value, FILTER_VALIDATE_BOOL),
            'string' => (string) $value,
            default => $value,
        };
    }
}
