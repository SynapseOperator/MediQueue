<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('refunds', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('payment_order_id')->unique();
            $table->string('refund_no', 40)->collation('utf8mb4_0900_bin')->unique();
            $table->decimal('amount', 10, 2);
            $table->string('status', 16)->collation('utf8mb4_0900_bin')->default('PENDING');
            $table->string('reason_code', 32)->collation('utf8mb4_0900_bin');
            $table->unsignedBigInteger('requested_by')->nullable();
            $table->string('external_refund_id', 128)->collation('utf8mb4_0900_bin')->nullable();
            $table->dateTime('succeeded_at', 3)->nullable();
            $table->string('last_error')->nullable();
            $table->dateTime('created_at', 3);
            $table->dateTime('updated_at', 3);

            $table->index(['status', 'created_at', 'id']);
            $table->foreign('payment_order_id')->references('id')->on('payment_orders')->restrictOnUpdate()->restrictOnDelete();
            $table->foreign('requested_by')->references('id')->on('users')->restrictOnUpdate()->restrictOnDelete();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE refunds
                ADD CONSTRAINT refunds_amount_check CHECK (amount > 0),
                ADD CONSTRAINT refunds_status_check CHECK (status IN ('PENDING', 'SUCCEEDED', 'FAILED')),
                ADD CONSTRAINT refunds_mock_fields_check CHECK (external_refund_id IS NULL),
                ADD CONSTRAINT refunds_success_check CHECK (
                    (status = 'SUCCEEDED' AND succeeded_at IS NOT NULL)
                    OR (status IN ('PENDING', 'FAILED') AND succeeded_at IS NULL)
                )
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('refunds');
    }
};
