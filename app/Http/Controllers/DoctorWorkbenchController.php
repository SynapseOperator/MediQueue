<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class DoctorWorkbenchController extends Controller
{
    public function index(Request $request): View
    {
        $doctor = DB::table('doctors')->where('user_id', $request->user()->id)->firstOrFail();
        $schedules = DB::table('schedules')->join('departments', 'departments.id', '=', 'schedules.department_id')
            ->where('schedules.doctor_id', $doctor->id)
            ->select('schedules.*', 'departments.name as department_name')
            ->orderByDesc('work_date')->orderBy('starts_at')->paginate(15);

        return view('doctor.workbench', compact('doctor', 'schedules'));
    }

    public function show(Request $request, int $schedule): View
    {
        $doctor = DB::table('doctors')->where('user_id', $request->user()->id)->firstOrFail();
        $schedule = DB::table('schedules')->where('doctor_id', $doctor->id)->where('id', $schedule)->firstOrFail();
        $slots = DB::table('slots')->where('schedule_id', $schedule->id)->orderBy('starts_at')->get();

        return view('doctor.schedule', compact('doctor', 'schedule', 'slots'));
    }
}
