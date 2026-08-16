<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Partners in a partnership, for the T5013 partner income allocation
     * (Schedule 50). Each holds an ownership share in basis points (10000 = 100%);
     * the partnership's net income is allocated across partners by share.
     */
    public function up(): void
    {
        Schema::create('partners', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('business_number', 32)->nullable();
            $table->unsignedInteger('share_bps')->default(0);
            $table->timestamps();

            $table->index('company_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('partners');
    }
};
