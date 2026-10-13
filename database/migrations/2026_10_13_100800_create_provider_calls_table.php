<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provider_calls', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('rep_id')->nullable()->constrained()->nullOnDelete();
            $table->date('week_start');
            $table->string('purpose', 16)->comment('sync|backfill');
            $table->string('outcome', 16)->comment('success|failure|timeout|rejected_cap');
            $table->unsignedInteger('duration_ms')->default(0);
            $table->timestamp('called_at');

            $table->index(['tenant_id', 'called_at']);
            $table->index(['tenant_id', 'rep_id', 'called_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provider_calls');
    }
};
