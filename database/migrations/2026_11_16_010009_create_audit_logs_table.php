<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table): void {
            $table->id();
            $table->string('actor_type', 16)->collation('utf8mb4_0900_bin');
            $table->unsignedBigInteger('operator_id')->nullable();
            $table->string('action', 64)->collation('utf8mb4_0900_bin');
            $table->string('target_type', 32)->collation('utf8mb4_0900_bin');
            $table->unsignedBigInteger('target_id');
            $table->json('before_state')->nullable();
            $table->json('after_state')->nullable();
            $table->string('request_id', 64)->collation('utf8mb4_0900_bin')->nullable();
            $table->dateTime('created_at', 3);

            $table->index(['target_type', 'target_id', 'created_at', 'id']);
            $table->foreign('operator_id')->references('id')->on('users')->restrictOnUpdate()->restrictOnDelete();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE audit_logs
                ADD CONSTRAINT audit_logs_actor_check CHECK (
                    (actor_type = 'USER' AND operator_id IS NOT NULL)
                    OR actor_type = 'SYSTEM'
                ),
                ADD CONSTRAINT audit_logs_target_check CHECK (
                    target_type IN ('users', 'patients', 'departments', 'doctors', 'schedules', 'slots', 'appointments', 'payment_orders', 'refunds')
                )
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
