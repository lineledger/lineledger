<?php

namespace App\Services\OpeningBalances\Importers;

use App\Models\Company;
use App\Models\Contact;
use App\Models\OpeningBalanceState;
use App\Services\Migration\Csv\CsvParser;
use App\Services\Migration\Importers\CompanyCsvImporter;
use App\Services\Migration\ImportResult;
use App\Services\OpeningBalances\VendorOpeningBalanceSync;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The vendor mirror of {@see OpeningCustomerBalancesCsvImporter}: sets each
 * vendor's net opening AP balance (signed; negative = vendor credit) through
 * {@see VendorOpeningBalanceSync::set()}.
 */
class OpeningVendorBalancesCsvImporter implements CompanyCsvImporter
{
    public function __construct(
        protected CsvParser $parser,
        protected VendorOpeningBalanceSync $sync,
    ) {}

    public function templateHeaders(): array
    {
        return ['vendor_display_name', 'balance'];
    }

    public function templateExampleRows(): array
    {
        return [
            ['vendor_display_name' => 'Office Supply Co.', 'balance' => '425.00'],
            ['vendor_display_name' => 'Fraser Fuel', 'balance' => '-30.00'],
        ];
    }

    public function previewForCompany(string $csvPath, Company $company): ImportResult
    {
        return $this->run($csvPath, $company, true);
    }

    public function commitForCompany(string $csvPath, Company $company): ImportResult
    {
        return $this->run($csvPath, $company, false);
    }

    protected function run(string $csvPath, Company $company, bool $dryRun): ImportResult
    {
        $state = OpeningBalanceState::for($company);

        if (! $state) {
            return new ImportResult($dryRun, [], [['row' => 0, 'message' => 'Open the Opening Balances workspace first.']]);
        }

        try {
            $rows = $this->parser->parse($csvPath, $this->templateHeaders());
        } catch (Throwable $e) {
            return new ImportResult($dryRun, [], [['row' => 0, 'message' => $e->getMessage()]]);
        }

        $errors = [];
        $preview = [];
        $accepted = [];

        $contacts = Contact::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->where('is_vendor', true)
            ->get()
            ->keyBy('display_name');

        foreach ($rows as $i => $row) {
            $rowNum = $i + 2;
            $name = $row['vendor_display_name'];
            $balance = CsvParser::parseCents($row['balance']);

            if (! $name || $balance === null) {
                $errors[] = ['row' => $rowNum, 'message' => 'vendor_display_name and balance are required.'];

                continue;
            }

            $contact = $contacts->get($name);

            if (! $contact) {
                $errors[] = ['row' => $rowNum, 'message' => "Vendor '{$name}' not found. Create vendors before importing balances."];

                continue;
            }

            if (isset($accepted[$contact->id])) {
                $errors[] = ['row' => $rowNum, 'message' => "Vendor '{$name}' appears more than once."];

                continue;
            }

            $current = $this->sync->currentFor($contact)['net'];
            $accepted[$contact->id] = ['contact' => $contact, 'balance' => $balance];

            $preview[] = [
                'row' => $rowNum,
                'name' => $name,
                'action' => $current === $balance ? 'unchanged' : ($current === 0 ? 'create' : 'update'),
                'balance' => CsvParser::centsLabel($balance),
            ];
        }

        $summary = [
            'rows' => count($rows),
            'created' => collect($preview)->where('action', 'create')->count(),
            'updated' => collect($preview)->where('action', 'update')->count(),
            'total_cents' => array_sum(array_map(fn ($a) => $a['balance'], $accepted)),
        ];

        if ($dryRun || $errors !== []) {
            return new ImportResult($dryRun, $preview, $errors, [], $summary);
        }

        try {
            DB::transaction(function () use ($state, $accepted): void {
                foreach ($accepted as $entry) {
                    $this->sync->set($state, $entry['contact'], $entry['balance']);
                }
            });
        } catch (Throwable $e) {
            $errors[] = ['row' => 0, 'message' => 'Import aborted: '.$e->getMessage()];
        }

        return new ImportResult($dryRun, $preview, $errors, [], $summary);
    }
}
