<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meetings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('external_id');
            $table->string('title', 512);
            $table->timestamp('start_at');
            $table->timestamp('end_at');
            $table->string('status', 16)->comment('confirmed|tentative|cancelled');
            $table->timestamp('removed_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'external_id']);
            $table->index(['tenant_id', 'start_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meetings');
    }
};
