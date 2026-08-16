<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default filesystem disk that should be used
    | by the framework. The "local" disk, as well as a variety of cloud
    | based disks are available to your application for file storage.
    |
    */

    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Below you may configure as many filesystem disks as necessary, and you
    | may even configure multiple disks for the same driver. Examples for
    | most supported storage drivers are configured here for reference.
    |
    | Supported drivers: "local", "ftp", "sftp", "s3"
    |
    */

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            // Private storage (backups, attachment blobs) is delivered only by
            // dedicated, authorization-checked controllers, never by the generic
            // `/storage/{path}` route — so keep that route unregistered.
            'serve' => false,
            'throw' => false,
            'report' => false,
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => rtrim(env('APP_URL', 'http://localhost'), '/').'/storage',
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

        // Private object storage: attachments and backup ZIPs. Nothing here is
        // web-readable — blobs are served by authorization-checked controllers,
        // exactly like the `local` disk above.
        //
        // `throw` is deliberately TRUE on both S3 disks (the local disks keep
        // the framework default of false). On a network-backed driver a silent
        // `false` return means the upload vanished while the database row that
        // points at it was still written — a dangling attachment. Fail loud.
        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION', 'ca-central-1'),
            'bucket' => env('AWS_BUCKET'),
            // `?: null` matters — a blank `AWS_URL=` line yields '' (not null),
            // and the S3 adapter tests these with isset(), which accepts ''. It
            // would then build every URL against an empty base and hand back a
            // relative path like "/company-logos/x.png". Blank must mean "unset".
            'url' => env('AWS_URL') ?: null,
            'endpoint' => env('AWS_ENDPOINT') ?: null,
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'visibility' => 'private',
            'throw' => true,
            'report' => false,
        ],

        // Public object storage: company logos only. These are rendered by
        // browsers (sidebar, customer portal) so they need a plain URL.
        //
        // Grant read access with a BUCKET POLICY on s3:GetObject, not an object
        // ACL — buckets created since 2023 default to "bucket owner enforced",
        // where writing an object with public visibility fails outright with
        // AccessControlListNotSupported. So no `visibility` key here; `url`
        // is what makes `->url()` resolve.
        's3_public' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION', 'ca-central-1'),
            'bucket' => env('AWS_PUBLIC_BUCKET'),
            // See the note on the `s3` disk: blank must collapse to null, or
            // every logo URL comes out relative and 404s on the app domain.
            'url' => env('AWS_PUBLIC_URL') ?: null,
            'endpoint' => env('AWS_ENDPOINT') ?: null,
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => true,
            'report' => false,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Storage Roles
    |--------------------------------------------------------------------------
    |
    | Which disk each *kind* of durable file lives on. Application code asks for
    | a role (see App\Support\Storage\StorageDisks), never a literal disk name,
    | so a deployment can move files to object storage without touching code.
    |
    | Every role defaults to the disk that role used before object storage was
    | an option, so a self-hosted install with no AWS_* configuration behaves
    | exactly as it always has. The hosted Canadian deployment sets all three
    | to their S3 equivalents (see README).
    |
    | Deliberately NOT covered: Livewire temporary uploads, migration CSV
    | staging, restore bundles, and backup/restore work directories. Those are
    | scratch space consumed and deleted within a single request or job, and
    | several of their consumers need a real filesystem path (ZipArchive,
    | pdftotext, getRealPath()) that object storage cannot provide.
    |
    */

    'roles' => [
        'attachments' => env('ATTACHMENT_DISK', 'local'),
        'logos' => env('LOGO_DISK', 'public'),
        'backups' => env('BACKUP_DISK', 'local'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    |
    | Here you may configure the symbolic links that will be created when the
    | `storage:link` Artisan command is executed. The array keys should be
    | the locations of the links and the values should be their targets.
    |
    */

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];
