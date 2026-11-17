<?php

namespace App\Http\Controllers;

use App\Enums\AppointmentStatus;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class DoctorWorkbenchController extends Controller
{
    public function index(Request $request): View
    {
        $input = $request->validate(['date' => ['nullable', 'date_format:Y-m-d']]);
        $doctor = DB::table('doctors')->where('user_id', $request->user()->id)->firstOrFail();
        $schedules = DB::table('schedules')->join('departments', 'departments.id', '=', 'schedules.department_id')
            ->where('schedules.doctor_id', $doctor->id)
            ->when($input['date'] ?? null, fn ($query, $date) => $query->where('schedules.work_date', $date))
            ->select('schedules.*', 'departments.name as department_name')
            ->orderByDesc('work_date')->orderBy('starts_at')->paginate(15)->withQueryString();

        return view('doctor.workbench', compact('doctor', 'schedules'));
    }

    public function show(Request $request, int $schedule): View
    {
        $doctor = DB::table('doctors')->where('user_id', $request->user()->id)->firstOrFail();
        $schedule = DB::table('schedules')->where('doctor_id', $doctor->id)->where('id', $schedule)->firstOrFail();
        $slots = DB::table('slots')->where('schedule_id', $schedule->id)->orderBy('starts_at')->get();
        $patients = DB::table('appointments')->join('slots', 'slots.id', '=', 'appointments.slot_id')
            ->join('patients', 'patients.id', '=', 'appointments.patient_id')
            ->where('slots.schedule_id', $schedule->id)->whereIn('appointments.status', [AppointmentStatus::Confirmed->value, AppointmentStatus::Completed->value])
            ->select('appointments.appointment_no', 'patients.full_name as patient_name', 'appointments.status', 'slots.starts_at', 'slots.ends_at')
            ->orderBy('slots.starts_at')->orderBy('appointments.id')->paginate(20);

        return view('doctor.schedule', compact('doctor', 'schedule', 'slots', 'patients'));
    }
}
