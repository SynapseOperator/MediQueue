<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('schedules', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('doctor_id');
            $table->unsignedBigInteger('department_id');
            $table->date('work_date');
            $table->string('session_code', 16)->collation('utf8mb4_0900_bin');
            $table->dateTime('starts_at', 3);
            $table->dateTime('ends_at', 3);
            $table->string('status', 16)->collation('utf8mb4_0900_bin')->default('DRAFT');
            $table->string('suspend_reason')->nullable();
            $table->unsignedBigInteger('created_by');
            $table->dateTime('created_at', 3);
            $table->dateTime('updated_at', 3);

            $table->unique(['doctor_id', 'work_date', 'session_code']);
            $table->index(['department_id', 'work_date', 'status']);
            $table->foreign('doctor_id')->references('id')->on('doctors')->restrictOnUpdate()->restrictOnDelete();
            $table->foreign('department_id')->references('id')->on('departments')->restrictOnUpdate()->restrictOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->restrictOnUpdate()->restrictOnDelete();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE schedules
                ADD CONSTRAINT schedules_time_check CHECK (ends_at > starts_at),
                ADD CONSTRAINT schedules_session_check CHECK (session_code IN ('MORNING', 'AFTERNOON', 'EVENING')),
                ADD CONSTRAINT schedules_status_check CHECK (status IN ('DRAFT', 'OPEN', 'SUSPENDED', 'CLOSED')),
                ADD CONSTRAINT schedules_suspension_check CHECK (
                    status <> 'SUSPENDED' OR (suspend_reason IS NOT NULL AND CHAR_LENGTH(TRIM(suspend_reason)) > 0)
                )
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('schedules');
    }
};
