<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('doctors', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id')->unique();
            $table->unsignedBigInteger('department_id');
            $table->string('full_name', 64);
            $table->string('title', 32);
            $table->string('specialty')->nullable();
            $table->text('introduction')->nullable();
            $table->string('status', 16)->collation('utf8mb4_0900_bin')->default('ACTIVE');
            $table->dateTime('created_at', 3);
            $table->dateTime('updated_at', 3);

            $table->index(['department_id', 'status', 'id']);
            $table->foreign('user_id')->references('id')->on('users')->restrictOnUpdate()->restrictOnDelete();
            $table->foreign('department_id')->references('id')->on('departments')->restrictOnUpdate()->restrictOnDelete();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE doctors
                ADD CONSTRAINT doctors_status_check CHECK (status IN ('ACTIVE', 'DISABLED'))
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('doctors');
    }
};
