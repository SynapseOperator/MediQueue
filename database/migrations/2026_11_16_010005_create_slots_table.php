<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('slots', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('schedule_id');
            $table->dateTime('starts_at', 3);
            $table->dateTime('ends_at', 3);
            $table->integer('capacity');
            $table->integer('occupied_count')->default(0);
            $table->decimal('fee_amount', 10, 2);
            $table->string('status', 16)->collation('utf8mb4_0900_bin')->default('OPEN');
            $table->dateTime('created_at', 3);
            $table->dateTime('updated_at', 3);

            $table->unique(['schedule_id', 'starts_at']);
            $table->foreign('schedule_id')->references('id')->on('schedules')->restrictOnUpdate()->restrictOnDelete();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE slots
                ADD CONSTRAINT slots_time_check CHECK (ends_at > starts_at),
                ADD CONSTRAINT slots_capacity_check CHECK (capacity > 0 AND occupied_count >= 0 AND occupied_count <= capacity),
                ADD CONSTRAINT slots_fee_check CHECK (fee_amount > 0),
                ADD CONSTRAINT slots_status_check CHECK (status IN ('OPEN', 'CLOSED'))
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('slots');
    }
};
