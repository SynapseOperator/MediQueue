<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('appointments', function (Blueprint $table): void {
            $table->id();
            $table->string('appointment_no', 40)->collation('utf8mb4_0900_bin')->unique();
            $table->unsignedBigInteger('patient_id');
            $table->unsignedBigInteger('slot_id');
            $table->string('request_key', 64)->collation('utf8mb4_0900_bin');
            $table->string('status', 24)->collation('utf8mb4_0900_bin')->default('PENDING_PAYMENT');
            $table->decimal('fee_amount', 10, 2);
            $table->dateTime('expires_at', 3);
            $table->dateTime('confirmed_at', 3)->nullable();
            $table->dateTime('cancelled_at', 3)->nullable();
            $table->unsignedBigInteger('cancelled_by')->nullable();
            $table->string('cancel_reason_code', 32)->collation('utf8mb4_0900_bin')->nullable();
            $table->string('cancel_reason')->nullable();
            $table->dateTime('released_at', 3)->nullable();
            $table->dateTime('completed_at', 3)->nullable();
            $table->unsignedBigInteger('completed_by')->nullable();
            $table->dateTime('created_at', 3);
            $table->dateTime('updated_at', 3);

            $table->unique(['patient_id', 'request_key']);
            $table->index(['patient_id', 'status', 'slot_id']);
            $table->index(['patient_id', 'created_at', 'id']);
            $table->index(['slot_id', 'status', 'expires_at', 'id']);
            $table->index(['status', 'expires_at', 'id']);
            $table->foreign('patient_id')->references('id')->on('patients')->restrictOnUpdate()->restrictOnDelete();
            $table->foreign('slot_id')->references('id')->on('slots')->restrictOnUpdate()->restrictOnDelete();
            $table->foreign('cancelled_by')->references('id')->on('users')->restrictOnUpdate()->restrictOnDelete();
            $table->foreign('completed_by')->references('id')->on('users')->restrictOnUpdate()->restrictOnDelete();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE appointments
                ADD CONSTRAINT appointments_status_check CHECK (status IN ('PENDING_PAYMENT', 'CONFIRMED', 'CANCELLED', 'EXPIRED', 'COMPLETED')),
                ADD CONSTRAINT appointments_fee_check CHECK (fee_amount > 0),
                ADD CONSTRAINT appointments_expiry_check CHECK (expires_at > created_at AND expires_at <= created_at + INTERVAL 15 MINUTE),
                ADD CONSTRAINT appointments_release_check CHECK (
                    (status IN ('CANCELLED', 'EXPIRED') AND released_at IS NOT NULL)
                    OR (status IN ('PENDING_PAYMENT', 'CONFIRMED', 'COMPLETED') AND released_at IS NULL)
                ),
                ADD CONSTRAINT appointments_confirmation_check CHECK (
                    (status IN ('CONFIRMED', 'COMPLETED') AND confirmed_at IS NOT NULL)
                    OR (status IN ('PENDING_PAYMENT', 'EXPIRED') AND confirmed_at IS NULL)
                    OR status = 'CANCELLED'
                ),
                ADD CONSTRAINT appointments_cancellation_check CHECK (
                    (status = 'CANCELLED' AND cancelled_at IS NOT NULL AND cancelled_by IS NOT NULL AND cancel_reason_code IS NOT NULL)
                    OR (status <> 'CANCELLED' AND cancelled_at IS NULL AND cancelled_by IS NULL AND cancel_reason_code IS NULL AND cancel_reason IS NULL)
                ),
                ADD CONSTRAINT appointments_completion_check CHECK (
                    (status = 'COMPLETED' AND completed_at IS NOT NULL AND completed_by IS NOT NULL)
                    OR (status <> 'COMPLETED' AND completed_at IS NULL AND completed_by IS NULL)
                ),
                ADD CONSTRAINT appointments_cancel_reason_check CHECK (
                    cancel_reason_code IS NULL OR cancel_reason_code = 'PATIENT_REQUEST'
                    OR (cancel_reason_code IN ('CLINIC_SUSPENDED', 'ADMIN_CORRECTION') AND cancel_reason IS NOT NULL AND CHAR_LENGTH(TRIM(cancel_reason)) > 0)
                )
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('appointments');
    }
};
