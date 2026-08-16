<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Platform-wide (cross-tenant) settings, keyed by string. Holds the
        // operator's kill switches — registrations on/off, per-section toggles,
        // maintenance mode — read through the cached App\Support\SiteSettings
        // accessor. A key/value store so new toggles need no further migration.
        Schema::create('site_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->json('value')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_settings');
    }
};
