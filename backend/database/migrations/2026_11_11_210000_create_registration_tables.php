<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 1: product registration (docs/PLAN.md section 1). All tenant-owned.
 *
 * - registrations: a dealer reporting a machine sold or rented out, with the
 *   customer's details as entered, waiting for the manufacturer's review.
 * - registration_documents: the files that go with it (bill of sale, PDI
 *   checklist). The file itself lives on the documents disk; this row holds
 *   what we know about it, including whether it was scanned.
 * - end_users: the manufacturer's customers. Created when a registration is
 *   approved - a rejected one never becomes a customer record.
 * - machines.end_user_id: who has the machine now (additive column).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('end_users', function (Blueprint $table) {
            $table->id();
            $table->foreignId('manufacturer_id')->constrained()->cascadeOnDelete();
            $table->string('company')->nullable();
            $table->string('contact_name');
            $table->string('email')->nullable();
            $table->string('phone', 40)->nullable();
            $table->string('address')->nullable();
            $table->string('city', 120)->nullable();
            $table->string('region', 120)->nullable();
            $table->string('postal_code', 20)->nullable();
            $table->string('country', 2)->nullable();
            $table->timestamps();
            $table->index(['manufacturer_id', 'email']);
        });

        Schema::create('registrations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('manufacturer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('machine_id')->constrained()->cascadeOnDelete();
            // The dealer that registered it - restrict, so a dealer's history can't vanish.
            $table->foreignId('dealer_link_id')->constrained()->restrictOnDelete();
            $table->string('type', 10); // sale | rental
            $table->string('status', 10)->default('pending'); // pending | approved | rejected
            $table->date('delivered_on');
            // The customer as the dealer entered it - kept as submitted, even
            // after approval copies it to end_users.
            $table->json('customer');
            $table->text('notes')->nullable();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->foreignId('end_user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
            $table->index(['manufacturer_id', 'status']);
            $table->index(['manufacturer_id', 'machine_id']);
        });

        Schema::create('registration_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('manufacturer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('registration_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 20); // bill_of_sale | pdi | other
            // What the person called it - shown, never used as a path.
            $table->string('original_name');
            // Where it is on the documents disk (generated, never user input).
            $table->string('path');
            $table->string('mime', 100);
            $table->unsignedBigInteger('size');
            $table->char('sha256', 64);
            $table->string('scan_status', 20); // clean | not_scanned
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::table('machines', function (Blueprint $table) {
            $table->foreignId('end_user_id')->nullable()->after('dealer_link_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('machines', function (Blueprint $table) {
            $table->dropConstrainedForeignId('end_user_id');
        });
        Schema::dropIfExists('registration_documents');
        Schema::dropIfExists('registrations');
        Schema::dropIfExists('end_users');
    }
};
