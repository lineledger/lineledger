<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Remove the SaaS subscription-billing, trial, and referral schema.
     *
     * LineLedger is self-hosted only: there is no hosted service to subscribe
     * to, so no company is ever read-only and none of this state has meaning.
     * The migrations that created it have been deleted, so a fresh install
     * never has these columns — this migration exists to converge instances
     * that ran those earlier migrations onto the same schema. Every drop is
     * therefore guarded: on a fresh database there is nothing to remove.
     *
     * `stripe_customer_id` is the company as a customer on the PLATFORM Stripe
     * account (subscription billing) and goes. `stripe_account_id` /
     * `stripe_connected_at` / `stripe_disconnected_at` are Stripe Connect —
     * a company's own customers paying its invoices — and stay.
     *
     * One-way: down() is intentionally a no-op. Restoring the columns would
     * bring back empty scaffolding for a feature that no longer has any code.
     */
    public function up(): void
    {
        Schema::dropIfExists('referrals');
        Schema::dropIfExists('company_subscriptions');

        // `stripe_customer_id` and `referral_code` carry unique indexes. MySQL
        // drops a column's index with it; SQLite keeps the index around and
        // errors on the next matching create, so drop the index explicitly
        // first and let a missing index be non-fatal on either driver.
        foreach (['companies_stripe_customer_id_unique', 'companies_referral_code_unique'] as $index) {
            try {
                Schema::table('companies', function (Blueprint $table) use ($index) {
                    $table->dropUnique($index);
                });
            } catch (Throwable) {
                // Index already absent (fresh install, or the driver dropped it
                // with an earlier column). Nothing to do.
            }
        }

        $columns = array_values(array_filter([
            'stripe_customer_id',
            'trial_ends_at',
            'trial_reminder_sent_at',
            'billing_exempt_at',
            'billing_exempt_reason',
            'billing_exempt_granted_by',
            'referral_code',
        ], fn (string $column): bool => Schema::hasColumn('companies', $column)));

        if ($columns === []) {
            return;
        }

        Schema::table('companies', function (Blueprint $table) use ($columns) {
            $table->dropColumn($columns);
        });
    }

    public function down(): void
    {
        // Intentionally irreversible — see the class docblock.
    }
};
