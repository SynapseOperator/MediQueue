<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_orders', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('appointment_id')->unique();
            $table->string('order_no', 40)->collation('utf8mb4_0900_bin')->unique();
            $table->decimal('amount', 10, 2);
            $table->char('currency', 3)->collation('utf8mb4_0900_bin')->default('CNY');
            $table->string('channel', 32)->collation('utf8mb4_0900_bin')->default('MOCK_WECHAT');
            $table->string('status', 24)->collation('utf8mb4_0900_bin')->default('UNPAID');
            $table->string('external_txn_id', 128)->collation('utf8mb4_0900_bin')->nullable();
            $table->dateTime('paid_at', 3)->nullable();
            $table->dateTime('external_paid_at', 3)->nullable();
            $table->dateTime('closed_at', 3)->nullable();
            $table->dateTime('created_at', 3);
            $table->dateTime('updated_at', 3);

            $table->unique(['channel', 'external_txn_id']);
            $table->index(['paid_at', 'id']);
            $table->foreign('appointment_id')->references('id')->on('appointments')->restrictOnUpdate()->restrictOnDelete();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE payment_orders
                ADD CONSTRAINT payment_orders_amount_check CHECK (amount > 0),
                ADD CONSTRAINT payment_orders_currency_check CHECK (currency = 'CNY'),
                ADD CONSTRAINT payment_orders_channel_check CHECK (channel = 'MOCK_WECHAT'),
                ADD CONSTRAINT payment_orders_status_check CHECK (status IN ('UNPAID', 'PAID', 'CLOSED', 'REFUND_PENDING', 'REFUNDED')),
                ADD CONSTRAINT payment_orders_mock_fields_check CHECK (external_txn_id IS NULL AND external_paid_at IS NULL),
                ADD CONSTRAINT payment_orders_paid_check CHECK (
                    (status IN ('PAID', 'REFUND_PENDING', 'REFUNDED') AND paid_at IS NOT NULL)
                    OR (status IN ('UNPAID', 'CLOSED') AND paid_at IS NULL)
                ),
                ADD CONSTRAINT payment_orders_closed_check CHECK (
                    (status = 'UNPAID' AND closed_at IS NULL)
                    OR (status = 'CLOSED' AND closed_at IS NOT NULL)
                    OR status IN ('PAID', 'REFUND_PENDING', 'REFUNDED')
                )
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_orders');
    }
};
