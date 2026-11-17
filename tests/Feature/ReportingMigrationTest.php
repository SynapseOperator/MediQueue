<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ReportingMigrationTest extends TestCase
{
    public function test_reporting_objects_can_be_dropped_and_recreated(): void
    {
        $migration = require database_path('migrations/2026_11_17_010000_create_reporting_objects.php');
        try {
            $migration->down();
            $this->assertSame(0, DB::table('information_schema.routines')->where('routine_schema', DB::getDatabaseName())->whereIn('routine_name', ['sp_department_statistics', 'fn_intervals_overlap'])->count());
            $this->assertSame(0, DB::table('information_schema.views')->where('table_schema', DB::getDatabaseName())->whereIn('table_name', ['v_appointment_details', 'v_slot_occupancy_check'])->count());
        } finally {
            $migration->up();
        }
        $this->assertSame(2, DB::table('information_schema.routines')->where('routine_schema', DB::getDatabaseName())->whereIn('routine_name', ['sp_department_statistics', 'fn_intervals_overlap'])->count());
        $this->assertSame(2, DB::table('information_schema.views')->where('table_schema', DB::getDatabaseName())->whereIn('table_name', ['v_appointment_details', 'v_slot_occupancy_check'])->count());
    }
}
