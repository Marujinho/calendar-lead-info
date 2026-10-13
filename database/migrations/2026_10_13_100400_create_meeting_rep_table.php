<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meeting_rep', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('meeting_id')->constrained()->cascadeOnDelete();
            $table->foreignId('rep_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['meeting_id', 'rep_id']);
            $table->index(['tenant_id', 'rep_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meeting_rep');
    }
};
