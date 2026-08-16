<?php

use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Documentation — Documents')] class extends Component {}; ?>

<section class="w-full p-6">
    <x-pages::docs.layout
        :heading="__('Documents')"
        :subheading="__('Store company-wide files in folders and find every attachment across your books in one place.')"
    >
        <flux:text>
            {{ __('The Documents area is a central place for files that belong to the whole company — incorporation paperwork, lease agreements, scanned receipts that have not been attached to a bill yet, anything you want kept together. It complements the per-transaction attachments you already drop onto invoices and bills. The examples below use our sample business, Demo Company Inc.') }}
        </flux:text>

        <x-docs.callout type="note" heading="{{ __('Documents vs. Inbox') }}">
            {{ __('Documents is for storing files. If you want to email or drop a receipt and have the app read it and prep an entry for you, use the Inbox instead — it stages incoming receipts, runs OCR, and promotes each one to a draft bill or expense you review and post. See the Inbox guide for that workflow.') }}
        </x-docs.callout>

        <flux:text>
            {{ __('Open Documents from the sidebar to see your folders. Each card shows how many subfolders and files it holds, and a Shared badge if other members have view access.') }}
        </flux:text>

        <x-docs.figure
            src="{{ asset('docs/screenshots/documents/index.png') }}"
            alt="{{ __('The Documents repository showing a grid of folder cards') }}"
            caption="{{ __('The Documents repository. Use New folder to start a folder, or Attachment index to jump to every file attached to a transaction.') }}"
        />

        {{-- ───────────────────────── Create a folder ───────────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('Create a folder') }}</flux:heading>
        <flux:text>
            {{ __('Folders organize what you upload. They are private to you by default — only you, plus Owners and Admins, can see them until you explicitly share them with other team members.') }}
        </flux:text>

        <p><strong>{{ __('To create a folder:') }}</strong></p>
        <ol class="list-decimal ps-6 space-y-1">
            <li>{{ __('Open Documents from the sidebar.') }}</li>
            <li>{{ __('Select New folder in the top-right corner.') }}</li>
            <li>{{ __('Enter a Folder name (for example, “Incorporation”).') }}</li>
            <li>{{ __('Select Create.') }}</li>
        </ol>

        <x-docs.callout type="tip">
            {{ __('Inside a folder you can select New subfolder to nest folders as deep as you need — Demo Company Inc. might keep an “Incorporation” folder with subfolders for “Articles” and “Bylaws”.') }}
        </x-docs.callout>

        {{-- ───────────────────────── Upload files ───────────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('Upload files into a folder') }}</flux:heading>
        <flux:text>
            {{ __('Open a folder to see its contents and the upload area at the bottom. You can drag files straight onto the page or click to pick them from your computer.') }}
        </flux:text>

        <p><strong>{{ __('To upload files:') }}</strong></p>
        <ol class="list-decimal ps-6 space-y-1">
            <li>{{ __('Open the folder you want to upload to.') }}</li>
            <li>{{ __('Drag one or more files onto the Add documents dropzone, or select it to choose files from your computer.') }}</li>
            <li>{{ __('Select Upload to commit them to the folder.') }}</li>
        </ol>

        <x-docs.figure
            src="{{ asset('docs/screenshots/documents/folder-show.png') }}"
            alt="{{ __('Inside a folder showing existing documents and the Add documents dropzone') }}"
            caption="{{ __('Inside a folder. Use the pencil icon next to a file to rename it or add a description; use the X to remove it.') }}"
        />

        <x-docs.callout type="note" heading="{{ __('What you can upload') }}">
            {{ __('Each file can be up to 10 MB. Allowed formats: PDF, images (PNG, JPG, JPEG, WEBP, GIF), Word (DOC, DOCX), Excel (XLS, XLSX), PowerPoint (PPT, PPTX), OpenDocument (ODT, ODS), CSV, and plain text — a wider set than transaction attachments elsewhere in the app, which share the same 10 MB limit but a narrower format list.') }}
        </x-docs.callout>

        {{-- ───────────────────────── Share a folder ───────────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('Share a folder with teammates') }}</flux:heading>
        <flux:text>
            {{ __('New folders are visible only to their creator plus Owners and Admins. Share a folder when you want a specific bookkeeper or accountant to be able to open it.') }}
        </flux:text>

        <p><strong>{{ __('To share a folder:') }}</strong></p>
        <ol class="list-decimal ps-6 space-y-1">
            <li>{{ __('Open the folder.') }}</li>
            <li>{{ __('Open the Actions menu in the top-right corner and choose Share.') }}</li>
            <li>{{ __('Tick the members who should be able to view this folder and its files.') }}</li>
            <li>{{ __('Select Save.') }}</li>
        </ol>

        <x-docs.callout type="tip">
            {{ __('The Actions menu is also where you Rename or Delete a folder. Deleting a folder removes everything inside it, including subfolders and files — there is no undo, so use Share rather than Delete if you only want to limit access.') }}
        </x-docs.callout>

        {{-- ───────────────────────── Attachment index ───────────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('Find any attachment with the Attachment index') }}</flux:heading>
        <flux:text>
            {{ __('The Attachment index is a single table of every file attached to a transaction across the company — bills, invoices, journal entries, fixed assets, bank reconciliations, tax returns, and more. Use it when you remember a file existed but cannot remember exactly where you put it.') }}
        </flux:text>

        <p><strong>{{ __('To open the Attachment index:') }}</strong></p>
        <ol class="list-decimal ps-6 space-y-1">
            <li>{{ __('Open Documents from the sidebar.') }}</li>
            <li>{{ __('Select Attachment index in the top-right corner.') }}</li>
            <li>{{ __('Use the search box to filter by file name. Select the Attached to link on any row to jump straight to the source transaction.') }}</li>
        </ol>

        <x-docs.figure
            src="{{ asset('docs/screenshots/documents/attached-index.png') }}"
            alt="{{ __('The Attachment index table listing every file attached to a transaction with file, description, source, uploader, date, and size columns') }}"
            caption="{{ __('The Attachment index. Folder documents are intentionally excluded — this is the cross-transaction view.') }}"
        />

        {{-- ───────────────────────── Descriptions ───────────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('Rename a file or add a description') }}</flux:heading>
        <flux:text>
            {{ __('A short description makes a file easier to recognize later — useful when the original filename is something like “scan_2026-05-12_001.pdf”.') }}
        </flux:text>

        <p><strong>{{ __('To edit a file’s description:') }}</strong></p>
        <ol class="list-decimal ps-6 space-y-1">
            <li>{{ __('From a folder, select the pencil icon next to the file and edit the File name or Description (up to 500 characters), then Save.') }}</li>
            <li>{{ __('From the Attachment index, select Add description (or the existing description text) in the Description column, type your note, and Save.') }}</li>
        </ol>

        {{-- ───────────────────────── Dropzone everywhere ───────────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('Drag-and-drop attachments anywhere') }}</flux:heading>
        <flux:text>
            {{ __('Every transaction screen that accepts files — invoices, bills, credit memos, vendor credits, journal entries, fixed assets, tax returns, bank reconciliations — uses the same dropzone. Drag a file onto it, or select it to pick from your computer. The dropzone shows the accepted formats and size cap for that area, and you can edit a description on each file before it is saved.') }}
        </flux:text>

        <x-docs.callout type="note">
            {{ __('Bank reconciliations now accept attachments too — drop your monthly bank statement PDF onto the reconciliation while you work. Any files you queue up before selecting Finish reconciling are uploaded automatically the moment the reconciliation completes, so the statement and the closed period stay together in the audit trail.') }}
        </x-docs.callout>

        {{-- ──────────────────────── Related ──────────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('Related') }}</flux:heading>
        <ul class="list-disc ps-6 space-y-1">
            <li>{{ __('Inbox — email or drop a receipt to have it read and turned into a draft bill or expense, instead of just filing the raw image here.') }}</li>
            <li>{{ __('Banking — attach a bank statement to a reconciliation so the proof and the close live in one place.') }}</li>
            <li>{{ __('Settings → Team — review who is an Owner or Admin (they can see every folder) and who you may want to share specific folders with.') }}</li>
            <li>{{ __('Backups — folder contents and per-transaction attachments are included in your company backup ZIP.') }}</li>
        </ul>
    </x-pages::docs.layout>
</section>
