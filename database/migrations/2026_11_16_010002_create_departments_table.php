<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('departments', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 32)->unique();
            $table->string('name', 64)->unique();
            $table->text('introduction')->nullable();
            $table->string('status', 16)->collation('utf8mb4_0900_bin')->default('ACTIVE');
            $table->dateTime('created_at', 3);
            $table->dateTime('updated_at', 3);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE departments
                ADD CONSTRAINT departments_status_check CHECK (status IN ('ACTIVE', 'DISABLED'))
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('departments');
    }
};
