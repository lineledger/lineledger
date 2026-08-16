<?php

namespace App\Http\Controllers\Inbound;

use App\Enums\InboxItemSource;
use App\Enums\InboxItemStatus;
use App\Http\Controllers\Controller;
use App\Jobs\Inbox\ProcessInboxItem;
use App\Models\Attachment;
use App\Models\Company;
use App\Models\InboxItem;
use App\Models\User;
use App\Support\Storage\StorageDisks;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Receives inbound-parse webhooks from an email provider (Mailgun / Postmark
 * style JSON) and stages forwarded attachments as document-inbox items.
 *
 * Security model, in order — each gate fails closed:
 *   1. The tenant is resolved from an opaque per-company routing token embedded
 *      in the forwarding address (`inbox+{token}@{domain}`); an unknown token or
 *      a company with inbound email switched off is a flat 404.
 *   2. The raw request body is verified against an HMAC-SHA256 signature using
 *      `config('inbox.email.signing_secret')` — mirrors the Stripe billing
 *      webhook's signature-first posture (no session/CSRF here).
 *   3. The sender (From) must be an active member of the resolved company; any
 *      other sender is logged and refused, so a leaked address can't be used to
 *      inject documents into a tenant's books.
 *
 * Only then is each attachment decoded, stored, and queued for OCR via the same
 * {@see ProcessInboxItem} job the drag-drop uploader uses.
 */
class InboundEmailController extends Controller
{
    public function __invoke(Request $request, string $token): JsonResponse
    {
        // 1. Resolve the tenant. Company is the (unscoped) tenant root, so this
        //    is a global lookup; a disabled or unknown token is indistinguishable
        //    from "no such endpoint" — a 404, never a 200.
        $company = Company::query()
            ->where('inbound_email_token', $token)
            ->where('inbound_email_enabled', true)
            ->first();

        abort_if($company === null, 404);

        // 2. Verify the HMAC signature over the raw body before reading anything.
        $secret = (string) config('inbox.email.signing_secret', '');

        // No secret configured ⇒ the feature can't be trusted; refuse rather than
        // accept unverifiable payloads.
        abort_if($secret === '', 404);

        $expected = hash_hmac('sha256', $request->getContent(), $secret);
        $provided = (string) $request->header('X-Inbound-Signature', '');

        abort_unless($provided !== '' && hash_equals($expected, $provided), 403, 'Invalid signature.');

        // 3. Allow-list the sender against the company's active members.
        $fromEmail = $this->extractEmail((string) ($request->input('From') ?? $request->input('from') ?? ''));

        /** @var User|null $sender */
        $sender = $fromEmail === ''
            ? null
            : $company->members()->where('users.email', $fromEmail)->first();

        if ($sender === null) {
            Log::warning('Inbound email rejected: sender is not a member of the company.', [
                'company_id' => $company->id,
                'from' => $fromEmail,
            ]);

            abort(403, 'Sender is not a recognized member of this organization.');
        }

        // 4. Stage each attachment. Bind the tenant so BelongsToCompany stamps
        //    company_id on the inbox items and attachments we create.
        app()->instance('current_company', $company);

        $allowed = array_map('strtolower', (array) config('inbox.allowed_extensions', ['pdf', 'png', 'jpg', 'jpeg', 'webp', 'gif']));
        $staged = 0;

        foreach ($this->attachments($request) as $attachment) {
            $name = $this->sanitizeFilename($attachment['name']);
            $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION) ?: '');

            if ($extension === '' || ! in_array($extension, $allowed, true)) {
                continue;
            }

            $binary = base64_decode($attachment['content'], true);

            if ($binary === false || $binary === '') {
                continue;
            }

            $mime = $attachment['content_type'];

            $item = InboxItem::create([
                'source' => InboxItemSource::Email,
                'status' => InboxItemStatus::Pending,
                'original_filename' => $name,
                'mime' => $mime,
                'sender_email' => $fromEmail,
                'created_by_user_id' => $sender->id,
            ]);

            $disk = StorageDisks::attachments();
            $path = 'attachments/'.$company->id.'/inbox_items/'.$item->id.'/'.Str::ulid().'.'.$extension;
            Storage::disk($disk)->put($path, $binary);

            $file = Attachment::create([
                'attachable_type' => $item->getMorphClass(),
                'attachable_id' => $item->id,
                'disk' => $disk,
                'path' => $path,
                'original_filename' => $name,
                'mime_type' => $mime,
                'size_bytes' => strlen($binary),
                'uploaded_by_id' => $sender->id,
            ]);

            $item->forceFill(['attachment_id' => $file->id])->save();

            ProcessInboxItem::dispatch($item->id);

            $staged++;
        }

        return response()->json(['received' => true, 'staged' => $staged]);
    }

    /**
     * Normalize the provider's attachment payload to a list of
     * `{name, content_type, content}` rows. Supports the common Postmark shape
     * (`Attachments: [{Name, ContentType, Content}]`) and a lowercase variant.
     *
     * @return list<array{name: string, content_type: string, content: string}>
     */
    private function attachments(Request $request): array
    {
        /** @var array<int, mixed> $raw */
        $raw = $request->input('Attachments') ?? $request->input('attachments') ?? [];

        $out = [];

        foreach ($raw as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $out[] = [
                'name' => (string) ($entry['Name'] ?? $entry['name'] ?? 'attachment'),
                'content_type' => (string) ($entry['ContentType'] ?? $entry['content_type'] ?? 'application/octet-stream'),
                'content' => (string) ($entry['Content'] ?? $entry['content'] ?? ''),
            ];
        }

        return $out;
    }

    /**
     * Extract the bare email address from a header value that may be
     * "Display Name <user@example.com>" or just "user@example.com".
     */
    private function extractEmail(string $value): string
    {
        if (preg_match('/<([^>]+)>/', $value, $m) === 1) {
            $value = $m[1];
        }

        return strtolower(trim($value));
    }

    /**
     * Strip path components and control characters from a provider-supplied
     * filename before it is stored or echoed.
     */
    private function sanitizeFilename(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = preg_replace('/[\x00-\x1F\x7F]/u', '', $name) ?? '';
        $name = trim($name);

        return $name === '' ? 'attachment' : Str::limit($name, 255, '');
    }
}
