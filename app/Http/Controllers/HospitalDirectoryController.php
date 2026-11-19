<?php

namespace App\Http\Controllers;

use App\Models\Department;
use App\Models\Doctor;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class HospitalDirectoryController extends Controller
{
    public function home(): View
    {
        return view('welcome', [
            'departments' => Department::query()->where('status', 'ACTIVE')->orderBy('name')->get(['id', 'name']),
            'directoryDoctors' => $this->publicDoctors()->orderBy('doctors.full_name')->get(),
        ]);
    }

    public function departments(): View
    {
        return view('hospital.departments', [
            'departments' => Department::query()->where('status', 'ACTIVE')->orderBy('name')->paginate(12),
        ]);
    }

    public function doctors(Request $request): View
    {
        $input = $request->validate(['department' => ['nullable', 'integer', 'min:1']]);
        $now = CarbonImmutable::now(config('mediqueue.hospital_timezone'));
        $nextDate = $this->openSlots($now)
            ->whereColumn('schedules.doctor_id', 'doctors.id')
            ->whereColumn('slots.occupied_count', '<', 'slots.capacity')
            ->selectRaw('MIN(schedules.work_date)');

        return view('hospital.doctors', [
            'doctors' => $this->publicDoctors()
                ->selectSub($nextDate, 'next_date')
                ->when($input['department'] ?? null, fn (EloquentBuilder $query, $id) => $query->where('doctors.department_id', $id))
                ->orderBy('doctors.id')->paginate(12)->withQueryString(),
            'departments' => Department::query()->where('status', 'ACTIVE')->orderBy('name')->get(['id', 'name']),
            'departmentId' => $input['department'] ?? null,
        ]);
    }

    public function show(Request $request, int $doctor): View
    {
        $doctor = $this->publicDoctors()->where('doctors.id', $doctor)->firstOrFail();
        $now = CarbonImmutable::now(config('mediqueue.hospital_timezone'));
        $today = $now->startOfDay();
        $lastDay = $today->addDays(config('mediqueue.availability_days') - 1);
        $input = $request->validate([
            'date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:'.$today->toDateString(), 'before_or_equal:'.$lastDay->toDateString()],
        ]);

        $query = $this->openSlots($now)->where('schedules.doctor_id', $doctor->id);
        $availability = (clone $query)->select('schedules.work_date')
            ->selectRaw('MAX(slots.occupied_count < slots.capacity) AS has_space')
            ->groupBy('schedules.work_date')->orderBy('schedules.work_date')->pluck('has_space', 'work_date');

        $selectedDate = $input['date'] ?? $availability->filter()->keys()->first() ?? $availability->keys()->first() ?? $today->toDateString();
        $slots = $query->where('schedules.work_date', $selectedDate)
            ->orderBy('slots.starts_at')->orderBy('slots.id')
            ->select('slots.id', 'slots.starts_at', 'slots.ends_at', 'slots.capacity', 'slots.occupied_count', 'slots.fee_amount', 'departments.name as department_name')
            ->paginate(20)->withQueryString();
        foreach ($slots as $slot) {
            $slot->local_start = CarbonImmutable::parse($slot->starts_at, 'UTC')->setTimezone($now->timezone);
            $slot->local_end = CarbonImmutable::parse($slot->ends_at, 'UTC')->setTimezone($now->timezone);
        }

        return view('hospital.doctor', compact('doctor', 'slots', 'today', 'lastDay', 'availability', 'selectedDate'));
    }

    private function openSlots(CarbonImmutable $now): Builder
    {
        return DB::table('slots')
            ->join('schedules', 'schedules.id', '=', 'slots.schedule_id')
            ->join('departments', 'departments.id', '=', 'schedules.department_id')
            ->where('schedules.status', 'OPEN')->where('departments.status', 'ACTIVE')->where('slots.status', 'OPEN')
            ->whereBetween('schedules.work_date', [$now->toDateString(), $now->addDays(config('mediqueue.availability_days') - 1)->toDateString()])
            ->where('slots.starts_at', '>', $now->utc()->format('Y-m-d H:i:s.v'));
    }

    /** @return EloquentBuilder<Doctor> */
    private function publicDoctors(): EloquentBuilder
    {
        return Doctor::query()
            ->join('departments', 'departments.id', '=', 'doctors.department_id')
            ->join('users', 'users.id', '=', 'doctors.user_id')
            ->where('doctors.status', 'ACTIVE')->where('departments.status', 'ACTIVE')
            ->where('users.status', 'ACTIVE')->where('users.role', 'DOCTOR')
            ->select('doctors.id', 'doctors.full_name', 'doctors.title', 'doctors.specialty', 'doctors.introduction', 'departments.name as department_name');
    }
}
