<?php

namespace App\Services\OpeningBalances\Importers;

use App\Models\Company;
use App\Models\Contact;
use App\Models\OpeningBalanceState;
use App\Services\Migration\Csv\CsvParser;
use App\Services\Migration\Importers\CompanyCsvImporter;
use App\Services\Migration\ImportResult;
use App\Services\OpeningBalances\CustomerOpeningBalanceSync;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Sets each customer's net opening AR balance from a CSV (signed; a negative
 * balance is a customer credit). Each row goes through
 * {@see CustomerOpeningBalanceSync::set()}, so re-importing corrected figures
 * reposts the same opening documents instead of stacking new ones.
 */
class OpeningCustomerBalancesCsvImporter implements CompanyCsvImporter
{
    public function __construct(
        protected CsvParser $parser,
        protected CustomerOpeningBalanceSync $sync,
    ) {}

    public function templateHeaders(): array
    {
        return ['customer_display_name', 'balance'];
    }

    public function templateExampleRows(): array
    {
        return [
            ['customer_display_name' => 'Acme Landscaping', 'balance' => '1250.00'],
            ['customer_display_name' => 'Beeline Couriers', 'balance' => '-75.00'],
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
            ->where('is_customer', true)
            ->get()
            ->keyBy('display_name');

        foreach ($rows as $i => $row) {
            $rowNum = $i + 2;
            $name = $row['customer_display_name'];
            $balance = CsvParser::parseCents($row['balance']);

            if (! $name || $balance === null) {
                $errors[] = ['row' => $rowNum, 'message' => 'customer_display_name and balance are required.'];

                continue;
            }

            $contact = $contacts->get($name);

            if (! $contact) {
                $errors[] = ['row' => $rowNum, 'message' => "Customer '{$name}' not found. Create customers before importing balances."];

                continue;
            }

            if (isset($accepted[$contact->id])) {
                $errors[] = ['row' => $rowNum, 'message' => "Customer '{$name}' appears more than once."];

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
