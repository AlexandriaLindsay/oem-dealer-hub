<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What OEM Dealer Hub staff changed in the staff console, and why. Platform
 * data, not tenant-owned: it has no manufacturer scope, and only the console
 * reads it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_actions', function (Blueprint $table) {
            $table->id();
            // Kept when the staff member's account goes, so the history stays.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('staff_name');
            $table->string('action', 40);
            $table->string('subject_type', 40);
            $table->unsignedBigInteger('subject_id');
            // The account's name at the time, so the entry still reads right if it's renamed or removed.
            $table->string('subject_label');
            $table->json('details')->nullable();
            $table->text('note');
            $table->timestamp('created_at')->useCurrent();
            $table->index(['subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_actions');
    }
};
