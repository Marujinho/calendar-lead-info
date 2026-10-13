<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('digest_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('rep_id')->constrained()->cascadeOnDelete();
            $table->date('local_date');
            $table->timestamp('scheduled_for')->comment('08:00 rep-local, stored as UTC');
            $table->string('status', 16)->default('pending')->comment('pending|sending|sent|skipped_empty|failed_no_data|failed_send');
            $table->string('failure_reason', 64)->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('data_refreshed_at')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamps();

            $table->unique(['rep_id', 'local_date']);
            $table->index(['tenant_id', 'local_date', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('digest_deliveries');
    }
};
