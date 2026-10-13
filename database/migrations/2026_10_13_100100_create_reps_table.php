<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reps', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('email');
            $table->string('name');
            $table->string('timezone', 64);
            $table->boolean('active')->default(true);
            $table->timestamp('backfilled_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'email']);
            $table->index(['tenant_id', 'active', 'timezone']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reps');
    }
};
