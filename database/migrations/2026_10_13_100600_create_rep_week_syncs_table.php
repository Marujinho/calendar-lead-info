<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rep_week_syncs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('rep_id')->constrained()->cascadeOnDelete();
            $table->date('week_start')->comment('Monday of the ISO week, rep-local');
            $table->timestamp('last_success_at')->nullable();
            $table->timestamp('last_attempt_at')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->string('last_error_class')->nullable();
            $table->timestamps();

            $table->unique(['rep_id', 'week_start']);
            $table->index(['tenant_id', 'week_start']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rep_week_syncs');
    }
};
