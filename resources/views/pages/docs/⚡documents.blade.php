<?php

use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Documentation — Documents')] class extends Component {}; ?>

<section class="w-full p-6">
    <x-pages::docs.layout
        :heading="__('Documents')"
        :subheading="__('Store organization-wide files in folders and find every attachment across your books in one place.')"
    >
        <flux:text>
            {{ __('The Documents area is a central place for files that belong to the whole organization — incorporation paperwork, lease agreements, insurance certificates, anything you want kept together rather than pinned to one transaction. It complements the per-record attachments you drop onto invoices, bills, cheques, and expenses, and the Attachment index lets you search those from here too. The examples below use our sample business, Demo Company Inc.') }}
        </flux:text>

        <x-docs.callout type="note" heading="{{ __('Documents vs. Inbox') }}">
            {{ __('Documents is for storing files. If you want to email or drop a receipt and have the app read it and prepare an entry for you, use the Inbox instead — it stages incoming receipts, reads them, and promotes each one to a draft bill or expense you review and post. See') }}
            <a class="underline" href="{{ route('docs.inbox') }}" wire:navigate>{{ __('Inbox') }}</a>
            {{ __('for that workflow.') }}
        </x-docs.callout>

        <flux:text>
            {{ __('Open Documents → Repository from the sidebar to see your folders. Each card shows how many subfolders and files it holds, plus a Shared badge when the folder has a share list. Two buttons sit in the top-right corner: Attachment index, which also has its own entry in the sidebar, and New folder.') }}
        </flux:text>

        <x-docs.figure
            src="{{ asset('docs/screenshots/documents/index.png') }}"
            alt="{{ __('The Documents repository showing a grid of folder cards with subfolder and file counts, one carrying a Shared badge, and the Attachment index and New folder buttons in the top-right corner') }}"
            caption="{{ __('The Documents repository. Use New folder to start a folder, or Attachment index to jump to every file attached to a transaction.') }}"
        />

        <x-docs.callout type="note" heading="{{ __('Who sees the Documents group') }}">
            {{ __('Owners, Admins, and Accountants get the Documents section automatically. A Custom member only sees the Documents group in the sidebar — and can only open a folder you share with them — when the Documents section is part of their access. Section access is set per member under Settings → Organizations; see') }}
            <a class="underline" href="{{ route('docs.settings') }}" wire:navigate>{{ __('Settings') }}</a>{{ __('.') }}
        </x-docs.callout>

        {{-- ───────────────────────── Create a folder ───────────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('Create a folder') }}</flux:heading>
        <flux:text>
            {{ __('Folders organize what you upload. They are private to you by default — only you, plus Owners and Admins, can see a folder until you explicitly share it with other team members.') }}
        </flux:text>

        <p><strong>{{ __('To create a folder:') }}</strong></p>
        <ol class="list-decimal ps-6 space-y-1">
            <li>{{ __('Open Documents → Repository from the sidebar.') }}</li>
            <li>{{ __('Select New folder in the top-right corner.') }}</li>
            <li>{{ __('Enter a Folder name (for example, “Incorporation”).') }}</li>
            <li>{{ __('Select Create.') }}</li>
        </ol>

        <x-docs.callout type="tip">
            {{ __('Inside a folder, select New subfolder to nest folders as deep as you need — Demo Company Inc. might keep an “Incorporation” folder with subfolders for “Articles” and “Bylaws”. A breadcrumb trail at the top of every folder takes you back up the tree; an ancestor you have not been given access to shows as Restricted in the trail rather than revealing its name.') }}
        </x-docs.callout>

        {{-- ───────────────────────── Upload files ───────────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('Upload files into a folder') }}</flux:heading>
        <flux:text>
            {{ __('Open a folder to see its contents: subfolders first, then the Documents list of files in this folder, and — if you can manage the folder — an Add documents dropzone at the bottom. You can drag files straight onto the dropzone or click it to pick them from your computer.') }}
        </flux:text>

        <p><strong>{{ __('To upload files:') }}</strong></p>
        <ol class="list-decimal ps-6 space-y-1">
            <li>{{ __('Open the folder you want to upload to.') }}</li>
            <li>{{ __('Drag one or more files onto the “Drag & drop files here, or click to browse” dropzone under Add documents, or click it to choose files from your computer.') }}</li>
            <li>{{ __('Select the Upload button — it reads “Upload 1 file(s)”, “Upload 2 file(s)” and so on, and appears as soon as at least one file is staged — to commit them to the folder.') }}</li>
        </ol>

        <x-docs.figure
            src="{{ asset('docs/screenshots/documents/folder-show.png') }}"
            alt="{{ __('Inside a folder: the breadcrumb, the New subfolder and Actions buttons, a Documents list of files each with a pencil and an X control, and the Add documents dropzone with a staged file and an Upload 1 file(s) button') }}"
            caption="{{ __('Inside a folder. Use the pencil icon next to a file to rename it or add a description; use the X to remove it. The Upload button appears once you have staged a file.') }}"
        />

        <x-docs.callout type="note" heading="{{ __('What you can upload') }}">
            {{ __('Each file can be up to 10 MB. Allowed formats: PDF, images (PNG, JPG, JPEG, WEBP, GIF), Word (DOC, DOCX), Excel (XLS, XLSX), PowerPoint (PPT, PPTX), OpenDocument (ODT, ODS), CSV, and plain text — a wider set than transaction attachments elsewhere in the app, which share the same 10 MB limit but accept only PDF, images, Word, and Excel.') }}
        </x-docs.callout>

        <flux:heading size="md" class="mt-6">{{ __('Open a file') }}</flux:heading>
        <flux:text>
            {{ __('Select a file name to open it. PDFs and images (PNG, JPG, GIF, WEBP) open in a new browser tab so you can read them without downloading — the small arrow that appears when you hover over the name tells you a file will open this way. Every other type, such as Word, Excel, CSV, or text, downloads to your computer. Attachments on invoices, bills, and every other record open and download the same way. A file in a folder is only served to members who can see that folder, so a link copied out of the page never bypasses sharing.') }}
        </flux:text>

        {{-- ───────────────────────── Share a folder ───────────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('Share a folder with teammates') }}</flux:heading>
        <flux:text>
            {{ __('New folders are visible only to their creator plus Owners and Admins. Share a folder when you want a specific bookkeeper or accountant to be able to open it. Sharing is read-only: the people you share with can open the folder and read or download its files, but they see no New subfolder button, no Actions menu, no dropzone, and no pencil or X next to a file.') }}
        </flux:text>

        <p><strong>{{ __('To share a folder:') }}</strong></p>
        <ol class="list-decimal ps-6 space-y-1">
            <li>{{ __('Open the folder.') }}</li>
            <li>{{ __('Open the Actions menu in the top-right corner and choose Share.') }}</li>
            <li>{{ __('Tick the members who should be able to view this folder and its files. The dialog lists every other member and reminds you that Owners and admins always have access, so ticking them gives them no extra access.') }}</li>
            <li>{{ __('Select Save. The folder card now carries a Shared badge.') }}</li>
        </ol>

        <x-docs.figure
            src="{{ asset('docs/screenshots/documents/share-modal.png') }}"
            alt="{{ __('The Share folder dialog with a checkbox per member, the note that Owners and admins always have access, and Cancel and Save buttons') }}"
            caption="{{ __('The Share folder dialog. Tick each member who should be able to view the folder; Owners and Admins can already see everything, so ticking them gives them no extra access.') }}"
        />

        <x-docs.callout type="warning" heading="{{ __('Sharing needs the Documents section too') }}">
            {{ __('Ticking a member is not enough on its own. A Custom member whose access does not include the Documents section cannot open a folder you share with them — the Documents group does not appear in their sidebar and the folder link is refused. Grant the section under Settings → Organizations first, then share the folder. Sharing also does not cascade: each subfolder has its own list, so a member shared on “Incorporation” only sees “Articles” inside it if you share that subfolder as well.') }}
        </x-docs.callout>

        <p><strong>{{ __('Who can do what:') }}</strong></p>
        <ul class="list-disc ps-6 space-y-1">
            <li>{{ __('Owners and Admins — open, upload to, rename, share, and delete every folder in the organization.') }}</li>
            <li>{{ __('The folder’s creator — the same, for the folders they created.') }}</li>
            <li>{{ __('A member the folder is shared with — open the folder and read or download its files, nothing more.') }}</li>
            <li>{{ __('Everyone else — cannot see the folder at all.') }}</li>
        </ul>

        <x-docs.callout type="tip">
            {{ __('The Actions menu — shown only to Owners, Admins, and the folder’s creator — is also where you Rename a folder or choose Delete folder. Deleting a folder removes everything inside it, including subfolders and files, and the files are purged from storage, not just hidden — there is no undo, so use Share rather than Delete folder if you only want to limit access.') }}
        </x-docs.callout>

        {{-- ───────────────────────── Attachment index ───────────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('Find any attachment with the Attachment index') }}</flux:heading>
        <flux:text>
            {{ __('The Attachment index is a single table of every file attached to a record across the organization — invoices, bills, credit memos, vendor credits, cheques, expenses, fixed assets, bank reconciliations, customer and vendor records, and receipts captured by the Inbox. Use it when you remember a file existed but cannot remember exactly where you put it. Files stored in Documents folders are intentionally left out — this is the cross-transaction view.') }}
        </flux:text>

        <p><strong>{{ __('To open the Attachment index:') }}</strong></p>
        <ol class="list-decimal ps-6 space-y-1">
            <li>{{ __('Open Documents → Attachment index from the sidebar, or select Attachment index in the top-right corner of the repository.') }}</li>
            <li>{{ __('Type into “Search file name…” to filter by file name. The search matches the file name only, not the description.') }}</li>
            <li>{{ __('Read the Attached to column to see which record holds the file. An invoice, bill, credit memo, or vendor credit shows its document number as a link that opens the record, and a fixed asset shows its name as a link. A customer or vendor row reads Contact followed by the contact’s name, with no link. A cheque, expense, bank reconciliation, or Inbox receipt row shows only the record type and its internal ID — “Cheque #12”, “Expense #5”, “BankReconciliation #3”, “InboxItem #8” — not the cheque number, the reference, or the statement date, and has no link either; use the file name, uploader, and date to place it, then open the record from its own area.') }}</li>
            <li>{{ __('Select Repository in the top-right corner to return to the folder grid.') }}</li>
        </ol>

        <x-docs.figure
            src="{{ asset('docs/screenshots/documents/attached-index.png') }}"
            alt="{{ __('The Attachment index table listing files with File, Description, Attached to, Uploaded by, Date, and Size columns, where an invoice row shows a linked document number, a vendor row shows Contact and the vendor’s name, and a cheque row shows Cheque and an internal ID as plain text') }}"
            caption="{{ __('The Attachment index, newest file first. An invoice or bill number in the Attached to column is a link; a customer or vendor row shows the contact’s name, and a cheque, expense, or reconciliation row shows only its record type and internal ID, with no link.') }}"
        />

        <x-docs.callout type="note">
            {{ __('The table shows the newest uploads first and 25 rows per page, with page links underneath. Each row also shows who uploaded the file, the date, and its size, and the file name opens or downloads the file exactly as it would from the record itself.') }}
        </x-docs.callout>

        {{-- ───────────────────────── Descriptions ───────────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('Rename a file or add a description') }}</flux:heading>
        <flux:text>
            {{ __('A short description makes a file easier to recognize later — useful when the original filename is something like “scan_2026-05-12_001.pdf”. It shows under the file name wherever the file is listed.') }}
        </flux:text>

        <p><strong>{{ __('To edit a file’s name or description:') }}</strong></p>
        <ol class="list-decimal ps-6 space-y-1">
            <li>{{ __('From a folder, select the pencil icon next to the file. In the Edit document dialog, change the File name or add a Description (up to 500 characters), then select Save.') }}</li>
            <li>{{ __('From the Attachment index, select Add description (or the existing description text) in the Description column, type your note in the Edit description dialog, and select Save. Only the description can be changed here — the file keeps its name.') }}</li>
        </ol>

        {{-- ───────────────────────── Attachments on records ───────────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('Attach files to transactions and records') }}</flux:heading>
        <flux:text>
            {{ __('Most records that back a number in your books carry an Attachments panel with the same dropzone you use in a folder. You will find it on the page of an invoice, credit memo, vendor credit, and fixed asset; on both the form and the page of a bill, a cheque, and an expense; on a bank reconciliation while you work it and on a completed one; and on customer and vendor records — the Attachments tab of the customer form, and the Attachments panel at the foot of a saved vendor’s form — for contracts, a void cheque, or a tax form that belongs to the contact rather than to one transaction.') }}
        </flux:text>

        <p><strong>{{ __('To attach a file to a record:') }}</strong></p>
        <ol class="list-decimal ps-6 space-y-1">
            <li>{{ __('Open the record and find the Attachments panel — it is below the totals on a bill, cheque, or expense form, and at the bottom of an invoice or bill page.') }}</li>
            <li>{{ __('Drag files onto the dropzone or click it to pick them. The panel notes the limit: PDF, images, or Office docs up to 10 MB each.') }}</li>
            <li>{{ __('On a saved record, select Upload 1 file(s) (the count follows what you staged). On a bill, cheque, expense, or customer you are still creating, the panel says “1 file(s) will be attached when you save” and the files go up with the record when you save or post it.') }}</li>
            <li>{{ __('Select the X next to a file to remove it; the app asks you to confirm.') }}</li>
        </ol>

        <x-docs.figure
            src="{{ asset('docs/screenshots/documents/attachment-card.png') }}"
            alt="{{ __('The Attachments panel on a cheque form with one attached file, the drag-and-drop dropzone noting PDF, images, or Office docs up to 10 MB each, and an Upload 1 file(s) button') }}"
            caption="{{ __('The Attachments panel on a cheque. Every record that accepts files uses this same panel; on a record you have not saved yet, the button is replaced by a note that the files will be attached when you save.') }}"
        />

        <x-docs.callout type="note" heading="{{ __('Attaching is not editing') }}">
            {{ __('One person edits a record at a time. If a teammate has a cheque, expense, or bill open for editing, its edit form shows you who is editing instead, and Owners and Admins can take over — but attaching or removing a file from the record’s own page still works, so you can file the receipt while they finish. See') }}
            <a class="underline" href="{{ route('docs.settings') }}" wire:navigate>{{ __('Settings → Edit locks') }}</a>
            {{ __('for how a lock is held and released.') }}
        </x-docs.callout>

        <flux:heading size="md" class="mt-6">{{ __('Attach the bank statement to a reconciliation') }}</flux:heading>
        <flux:text>
            {{ __('A reconciliation in progress has a Statement & documents panel under the Reconcile now button. Drop the monthly statement PDF there while you work. Files you stage are held until you finish: the panel says “1 file(s) will be attached when you reconcile”, and selecting Reconcile now uploads them the moment the reconciliation completes, so the statement and the closed period stay together in your audit trail. If you would rather not wait, select Upload now beside that message. A completed reconciliation keeps the same panel, so you can add a statement later, and a statement you drop onto the Begin reconciliation panel to auto-fill the ending balance is carried into the attachments too.') }}
        </flux:text>

        <x-docs.figure
            src="{{ asset('docs/screenshots/documents/reconciliation-attachments.png') }}"
            alt="{{ __('The Statement & documents panel on a reconciliation in progress, with a staged statement PDF, the note that 1 file(s) will be attached when you reconcile, and the Upload now button, below the Reconcile now button') }}"
            caption="{{ __('Statement & documents on a reconciliation in progress. Staged files are attached when you select Reconcile now, or straight away with Upload now.') }}"
        />

        {{-- ──────────────────────── Related ──────────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('Related') }}</flux:heading>
        <ul class="list-disc ps-6 space-y-1">
            <li><a class="underline" href="{{ route('docs.inbox') }}" wire:navigate>{{ __('Inbox') }}</a> {{ __('— email or drop a receipt to have it read and turned into a draft bill or expense, instead of just filing the raw image here.') }}</li>
            <li><a class="underline" href="{{ route('docs.banking') }}" wire:navigate>{{ __('Banking') }}</a> {{ __('— reconciling an account, including dropping a statement onto the Begin reconciliation panel to auto-fill the ending balance.') }}</li>
            <li><a class="underline" href="{{ route('docs.settings') }}" wire:navigate>{{ __('Settings') }}</a> {{ __('— the Company members section under Settings → Organizations is where you see who is an Owner or Admin (they can see every folder) and give a Custom member the Documents section before you share with them.') }}</li>
            <li><a class="underline" href="{{ route('docs.settings') }}" wire:navigate>{{ __('Settings → Backup & export') }}</a> {{ __('— folder contents and per-record attachments are included in your backup ZIP.') }}</li>
        </ul>
    </x-pages::docs.layout>
</section>
