<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('daily_insights', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->date('insight_date'); // company-local calendar day
            $table->string('type', 60); // detector key (open set — see InsightDetectorRegistry)
            $table->string('source', 12); // InsightSource enum: ai|template
            $table->string('headline', 160);
            $table->text('body');
            $table->json('facts')->nullable(); // winning candidate's facts; money stays integer cents
            $table->timestamps();

            // One insight per company per day; doubles as the card/history index.
            $table->unique(['company_id', 'insight_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_insights');
    }
};
