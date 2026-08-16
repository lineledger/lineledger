<?php

use App\Enums\AccountSubtype;
use App\Enums\CompanyBackupStatus;
use App\Enums\CompanyRole;
use App\Enums\InvoiceStatus;
use App\Jobs\ExportCompanyDataJob;
use App\Models\Account;
use App\Models\Attachment;
use App\Models\Bill;
use App\Models\BillLine;
use App\Models\Company;
use App\Models\CompanyBackup;
use App\Models\Contact;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Item;
use App\Models\JournalEntry;
use App\Models\User;
use App\Services\Backup\CompanyExporter;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    Storage::fake('public');

    $this->owner = User::factory()->create([
        'name' => 'Owen Owner',
        'email' => 'owen@example.test',
    ]);
    $this->editor = User::factory()->create([
        'name' => 'Edie Editor',
        'email' => 'edie@example.test',
    ]);

    $this->company = Company::factory()->create(['name' => 'Acme Inc.']);
    $this->company->members()->attach($this->owner, ['role' => CompanyRole::Owner->value]);
    $this->company->members()->attach($this->editor, ['role' => CompanyRole::Admin->value]);

    app()->instance('current_company', $this->company);

    // Customer + vendor — Contact factory exists with named states.
    $this->customer = Contact::factory()->customer()->create(['display_name' => 'Wile E. Coyote']);
    $this->vendor = Contact::factory()->vendor()->create(['display_name' => 'Acme Supplies']);

    // Pull a couple of accounts from the auto-seeded chart.
    $this->bankAccount = Account::query()
        ->where('subtype', AccountSubtype::Bank->value)
        ->firstOrFail();
    $this->incomeAccount = Account::query()
        ->where('subtype', AccountSubtype::Income->value)
        ->firstOrFail();
    $this->expenseAccount = Account::query()
        ->where('subtype', AccountSubtype::Expense->value)
        ->firstOrFail();

    // One item used by both the invoice and bill — gives us a non-trivial Item row.
    $this->item = Item::factory()->create([
        'name' => 'Widget',
        'default_price_cents' => 5000,
    ]);

    // Invoice with 3 lines (Draft so the GL is not auto-posted — we just want
    // a non-trivial row count surfaced in the manifest).
    $this->invoice = Invoice::create([
        'contact_id' => $this->customer->id,
        'invoice_no' => 'INV-1001',
        'invoice_date' => '2026-05-01',
        'due_date' => '2026-05-31',
        'status' => InvoiceStatus::Draft,
    ]);
    foreach ([1, 2, 3] as $i) {
        InvoiceLine::create([
            'invoice_id' => $this->invoice->id,
            'item_id' => $this->item->id,
            'account_id' => $this->incomeAccount->id,
            'description' => 'Widget '.$i,
            'quantity' => $i,
            'unit_price_cents' => 1000 * $i,
            'line_subtotal_cents' => 1000 * $i,
            'line_total_cents' => 1000 * $i,
            'line_order' => $i,
        ]);
    }

    // Bill with 2 lines, also Draft.
    $this->bill = Bill::create([
        'contact_id' => $this->vendor->id,
        'bill_no' => 'BILL-2001',
        'bill_date' => '2026-05-02',
        'due_date' => '2026-06-01',
    ]);
    foreach ([1, 2] as $i) {
        BillLine::create([
            'bill_id' => $this->bill->id,
            'item_id' => $this->item->id,
            'account_id' => $this->expenseAccount->id,
            'description' => 'Supplies '.$i,
            'quantity' => $i,
            'unit_price_cents' => 750 * $i,
            'line_subtotal_cents' => 750 * $i,
            'line_total_cents' => 750 * $i,
            'line_order' => $i,
        ]);
    }

    // A standalone journal entry (Draft — `journal_lines` is exported via
    // its parent's company-id subquery; this exercises that scope closure).
    $this->journalEntry = JournalEntry::create([
        'entry_no' => 'JE-001',
        'entry_date' => CarbonImmutable::create(2026, 5, 3),
        'memo' => 'opening',
        'posted_by_user_id' => $this->owner->id,
    ]);
    $this->journalEntry->lines()->create([
        'account_id' => $this->bankAccount->id,
        'debit_cents' => 100_000,
        'credit_cents' => 0,
        'line_order' => 0,
    ]);
    $this->journalEntry->lines()->create([
        'account_id' => $this->incomeAccount->id,
        'debit_cents' => 0,
        'credit_cents' => 100_000,
        'line_order' => 1,
    ]);

    // One real attachment blob — gets copied into the bundle's files/.
    Storage::disk('local')->put(
        'attachments/'.$this->company->id.'/customer-portrait.png',
        'PORTRAIT-PNG-BYTES',
    );
    $this->attachment = Attachment::create([
        'attachable_type' => (new Contact)->getMorphClass(),
        'attachable_id' => $this->customer->id,
        'disk' => 'local',
        'path' => 'attachments/'.$this->company->id.'/customer-portrait.png',
        'original_filename' => 'portrait.png',
        'mime_type' => 'image/png',
        'size_bytes' => strlen('PORTRAIT-PNG-BYTES'),
        'uploaded_by_id' => $this->editor->id,
    ]);
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

it('exports a company into a verifiable ZIP with manifest, jsonl, users, and files', function () {
    $backup = CompanyBackup::create([
        'status' => CompanyBackupStatus::Pending,
        'requested_by_user_id' => $this->owner->id,
        'app_version' => config('version.app'),
        'schema_version' => config('version.schema'),
    ]);

    $backup = app(CompanyExporter::class)->export($backup);

    expect($backup->status)->toBe(CompanyBackupStatus::Ready)
        ->and($backup->file_path)->not->toBeNull()
        ->and($backup->sha256)->not->toBeNull()
        ->and($backup->file_size_bytes)->toBeGreaterThan(0)
        ->and($backup->expires_at)->not->toBeNull();

    $zipAbsolute = Storage::disk('local')->path($backup->file_path);
    expect(file_exists($zipAbsolute))->toBeTrue();

    expect($backup->sha256)->toBe(hash_file('sha256', $zipAbsolute));

    // --- Unpack and inspect the bundle ---------------------------------
    $unpacked = sys_get_temp_dir().'/backup-e2e-'.uniqid();
    mkdir($unpacked, 0755, true);

    try {
        $zip = new ZipArchive;
        expect($zip->open($zipAbsolute))->toBeTrue();
        $zip->extractTo($unpacked);
        $zip->close();

        // manifest.json
        $manifestPath = $unpacked.'/manifest.json';
        expect(file_exists($manifestPath))->toBeTrue();

        $manifest = json_decode(file_get_contents($manifestPath), true, flags: JSON_THROW_ON_ERROR);

        expect($manifest['schema_version'])->toBe(1)
            ->and($manifest['app_version'])->toBe((string) config('version.app'))
            ->and($manifest['company']['id'])->toBe($this->company->id)
            ->and($manifest['exported_by']['email'])->toBe('owen@example.test');

        // Per-table row counts surface what we seeded.
        expect($manifest['tables']['invoices']['rows'])->toBe(1)
            ->and($manifest['tables']['invoice_lines']['rows'])->toBe(3)
            ->and($manifest['tables']['bills']['rows'])->toBe(1)
            ->and($manifest['tables']['bill_lines']['rows'])->toBe(2)
            ->and($manifest['tables']['journal_entries']['rows'])->toBe(1)
            ->and($manifest['tables']['journal_lines']['rows'])->toBe(2)
            ->and($manifest['tables']['contacts']['rows'])->toBeGreaterThanOrEqual(2)
            ->and($manifest['tables']['attachments']['rows'])->toBe(1)
            ->and($manifest['tables']['companies']['rows'])->toBe(1);

        // exclusions array surfaces the meta-table guard.
        $exclusionTables = array_column($manifest['exclusions'], 'table');
        expect($exclusionTables)->toContain('security_logs')
            ->and($exclusionTables)->toContain('company_backups');

        // users.json includes both seeded users by email.
        $usersJson = json_decode(file_get_contents($unpacked.'/users.json'), true, flags: JSON_THROW_ON_ERROR);
        $emails = array_column($usersJson, 'email');
        expect($emails)->toContain('owen@example.test')
            ->and($emails)->toContain('edie@example.test');

        // journal_lines.jsonl: first row parses + has the expected money columns.
        $journalLinesPath = $unpacked.'/data/journal_lines.jsonl';
        expect(file_exists($journalLinesPath))->toBeTrue();
        $firstLine = strtok(file_get_contents($journalLinesPath), "\n");
        $jl = json_decode($firstLine, true, flags: JSON_THROW_ON_ERROR);
        expect($jl)->toHaveKey('debit_cents')
            ->and($jl)->toHaveKey('credit_cents')
            ->and($jl)->toHaveKey('journal_entry_id');

        // attachments.jsonl: the row's `path` was rewritten to the in-bundle path.
        $attachmentsLine = strtok(file_get_contents($unpacked.'/data/attachments.jsonl'), "\n");
        $att = json_decode($attachmentsLine, true, flags: JSON_THROW_ON_ERROR);
        expect($att['id'])->toBe($this->attachment->id)
            ->and($att['path'])->toStartWith('files/attachments/')
            ->and($att['path'])->toEndWith('portrait.png');

        // The actual blob is in the bundle and byte-identical to the source.
        $bundledBlob = $unpacked.'/'.$att['path'];
        expect(file_exists($bundledBlob))->toBeTrue()
            ->and(file_get_contents($bundledBlob))->toBe('PORTRAIT-PNG-BYTES');

        // companies.jsonl: Stripe columns stripped, single row for this company.
        $companiesLine = strtok(file_get_contents($unpacked.'/data/companies.jsonl'), "\n");
        $coRow = json_decode($companiesLine, true, flags: JSON_THROW_ON_ERROR);
        expect($coRow['id'])->toBe($this->company->id)
            ->and($coRow)->not->toHaveKey('stripe_account_id')
            ->and($coRow)->not->toHaveKey('stripe_connected_at');
    } finally {
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($unpacked, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($it as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($unpacked);
    }
});

it('runs the export end-to-end via the dispatched job and lands in Ready status', function () {
    $backup = CompanyBackup::create([
        'status' => CompanyBackupStatus::Pending,
        'requested_by_user_id' => $this->owner->id,
        'app_version' => config('version.app'),
        'schema_version' => config('version.schema'),
    ]);

    // Run the job in-process so we exercise the handle() path itself
    // (queue.connection=sync in tests means dispatch goes through it directly).
    (new ExportCompanyDataJob($backup))->handle(app(CompanyExporter::class));

    $fresh = $backup->fresh();
    expect($fresh)->not->toBeNull()
        ->and($fresh->status)->toBe(CompanyBackupStatus::Ready)
        ->and($fresh->file_path)->not->toBeNull();
});

it('records Failed status and surfaces the error message when the exporter throws', function () {
    Bus::fake();

    // Build a fresh company with no Owner membership, then create a backup
    // with no requested_by_user_id. The exporter's manifest stamp requires
    // a User from one of those two sources, so it will throw inside its
    // try block — which is precisely the failure path we want to exercise.
    $orphanedCompany = Company::factory()->create(['name' => 'Lonely Inc.']);
    app()->instance('current_company', $orphanedCompany);

    $backup = CompanyBackup::create([
        'status' => CompanyBackupStatus::Pending,
        'requested_by_user_id' => null,
        'app_version' => config('version.app'),
        'schema_version' => config('version.schema'),
    ]);

    expect(fn () => app(CompanyExporter::class)->export($backup))
        ->toThrow(RuntimeException::class);

    $fresh = $backup->fresh();
    expect($fresh->status)->toBe(CompanyBackupStatus::Failed)
        ->and($fresh->error_message)->not->toBeNull();
});
