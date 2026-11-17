<?php

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class MysqlIntegrityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // These disposable fixtures prove MySQL capabilities, not P1 business rules.
        Schema::dropIfExists('p0_probe_children');
        Schema::dropIfExists('p0_probe_parents');

        Schema::create('p0_probe_parents', function (Blueprint $table): void {
            $table->id();
            $table->string('label')->unique();
            $table->decimal('amount', 10, 2);
        });

        Schema::create('p0_probe_children', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('parent_id')->constrained('p0_probe_parents')->restrictOnDelete();
            $table->integer('quantity');
        });

        DB::statement('ALTER TABLE p0_probe_children ADD CONSTRAINT p0_quantity_positive CHECK (quantity > 0)');

        $this->beforeApplicationDestroyed(function (): void {
            Schema::dropIfExists('p0_probe_children');
            Schema::dropIfExists('p0_probe_parents');
        });
    }

    public function test_utf8mb4_and_decimal_values_round_trip_without_loss(): void
    {
        $id = DB::table('p0_probe_parents')->insertGetId(['label' => '合成探针 🏥', 'amount' => '12.34']);
        $record = DB::table('p0_probe_parents')->find($id);

        $this->assertSame('合成探针 🏥', $record->label);
        $this->assertSame('12.34', $record->amount);
    }

    public function test_foreign_key_rejects_an_orphan_record(): void
    {
        try {
            DB::table('p0_probe_children')->insert(['parent_id' => 999, 'quantity' => 1]);
            $this->fail('MySQL accepted an orphan foreign key.');
        } catch (QueryException $exception) {
            $this->assertSame(1452, $exception->errorInfo[1]);
        }

        $this->assertDatabaseCount('p0_probe_children', 0);
    }

    public function test_check_constraint_rejects_invalid_values(): void
    {
        $id = DB::table('p0_probe_parents')->insertGetId(['label' => 'synthetic-parent', 'amount' => '1.00']);

        try {
            DB::table('p0_probe_children')->insert(['parent_id' => $id, 'quantity' => 0]);
            $this->fail('MySQL accepted a violated CHECK constraint.');
        } catch (QueryException $exception) {
            $this->assertSame(3819, $exception->errorInfo[1]);
        }

        $this->assertDatabaseCount('p0_probe_children', 0);
    }

    public function test_restrict_foreign_key_prevents_deleting_a_referenced_parent(): void
    {
        $id = DB::table('p0_probe_parents')->insertGetId(['label' => 'synthetic-parent', 'amount' => '1.00']);
        DB::table('p0_probe_children')->insert(['parent_id' => $id, 'quantity' => 1]);

        try {
            DB::table('p0_probe_parents')->where('id', $id)->delete();
            $this->fail('MySQL deleted a referenced parent despite RESTRICT.');
        } catch (QueryException $exception) {
            $this->assertSame(1451, $exception->errorInfo[1]);
        }

        $this->assertDatabaseCount('p0_probe_parents', 1);
        $this->assertDatabaseCount('p0_probe_children', 1);
    }

    public function test_transaction_exception_rolls_back_both_related_writes(): void
    {
        try {
            DB::transaction(function (): void {
                $id = DB::table('p0_probe_parents')->insertGetId(['label' => 'synthetic-parent', 'amount' => '1.00']);
                DB::table('p0_probe_children')->insert(['parent_id' => $id, 'quantity' => 1]);

                throw new RuntimeException('P0 injected rollback');
            });
        } catch (RuntimeException $exception) {
            $this->assertSame('P0 injected rollback', $exception->getMessage());
        }

        $this->assertDatabaseCount('p0_probe_parents', 0);
        $this->assertDatabaseCount('p0_probe_children', 0);
        $this->assertSame(0, DB::transactionLevel());
    }
}
