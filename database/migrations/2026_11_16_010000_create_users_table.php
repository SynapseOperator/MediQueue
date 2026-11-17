<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('username', 64)->unique();
            $table->string('password_hash');
            $table->string('role', 16)->collation('utf8mb4_0900_bin');
            $table->string('status', 16)->collation('utf8mb4_0900_bin')->default('ACTIVE');
            $table->dateTime('created_at', 3);
            $table->dateTime('updated_at', 3);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE users
                ADD CONSTRAINT users_role_check CHECK (role IN ('PATIENT', 'DOCTOR', 'ADMIN')),
                ADD CONSTRAINT users_status_check CHECK (status IN ('ACTIVE', 'DISABLED'))
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
