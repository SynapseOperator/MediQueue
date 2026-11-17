<?php

namespace App\Http\Controllers;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class AdminResourceController extends Controller
{
    public function index()
    {
        return view('admin.resources', [
            'departments' => DB::table('departments')->orderBy('id')->get(),
            'doctors' => DB::table('doctors')->orderBy('id')->get(),
            'schedules' => DB::table('schedules')->orderBy('starts_at')->get(),
            'slots' => DB::table('slots')->orderBy('starts_at')->get(),
        ]);
    }

    private function audit(string $table, int $id, string $action, ?array $before, array $after): void
    {
        DB::table('audit_logs')->insert([
            'actor_type' => 'USER', 'operator_id' => auth()->id(), 'action' => $action,
            'target_type' => $table, 'target_id' => $id,
            'before_state' => $before === null ? null : json_encode($before, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'after_state' => json_encode($after, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'created_at' => now('UTC')->format('Y-m-d H:i:s.v'),
        ]);
    }

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }

    public function storeDepartment(Request $request)
    {
        return $this->department($request, null);
    }

    public function updateDepartment(Request $request, int $id)
    {
        return $this->department($request, $id);
    }

    private function department(Request $request, ?int $id)
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:32', Rule::unique('departments')->ignore($id)],
            'name' => ['required', 'string', 'max:64', Rule::unique('departments')->ignore($id)],
            'introduction' => ['nullable', 'string', 'max:16000'], 'status' => ['required', Rule::in(['ACTIVE', 'DISABLED'])],
        ]);
        try {
            DB::transaction(function () use ($data, $id): void {
                $now = now('UTC')->format('Y-m-d H:i:s.v');
                if ($id === null) {
                    $id = DB::table('departments')->insertGetId($data + ['created_at' => $now, 'updated_at' => $now]);
                    $before = null;
                } else {
                    $old = DB::table('departments')->where('id', $id)->lockForUpdate()->first();
                    abort_unless($old !== null, 404);
                    $before = ['code' => $old->code, 'name' => $old->name, 'status' => $old->status];
                    DB::table('departments')->where('id', $id)->update($data + ['updated_at' => $now]);
                }
                $this->audit('departments', $id, 'DEPARTMENT_SAVED', $before, array_intersect_key($data, array_flip(['code', 'name', 'status'])));
            }, 3);
        } catch (UniqueConstraintViolationException $e) {
            $this->fail('name', '该账号、编码或名称已被使用。');
        }

        return redirect()->route('admin.resources')->with('status', '资源已保存。');
    }

    public function storeDoctor(Request $request)
    {
        return $this->doctor($request, null);
    }

    public function updateDoctor(Request $request, int $id)
    {
        return $this->doctor($request, $id);
    }

    private function doctor(Request $request, ?int $id)
    {
        $rules = [
            'department_id' => ['required', 'integer', 'exists:departments,id'], 'full_name' => ['required', 'string', 'max:64'],
            'title' => ['required', 'string', 'max:32'], 'specialty' => ['nullable', 'string', 'max:255'],
            'introduction' => ['nullable', 'string', 'max:16000'], 'status' => ['required', Rule::in(['ACTIVE', 'DISABLED'])],
        ];
        if ($id === null) {
            if (is_string($request->input('username'))) {
                $request->merge(['username' => User::normalizeUsername($request->input('username'))]);
            }
            $rules['username'] = [...User::usernameRules(), Rule::unique('users', 'username')];
            $rules['password'] = ['required', 'string', Password::defaults(), 'confirmed'];
        }
        $data = $request->validate($rules);
        try {
            DB::transaction(function () use ($data, $id): void {
                $old = $id === null ? null : DB::table('doctors')->where('id', $id)->first();
                if ($id !== null) {
                    abort_unless($old !== null, 404);
                }
                $departmentIds = array_unique(array_filter([$old?->department_id, (int) $data['department_id']]));
                sort($departmentIds);
                $departments = DB::table('departments')->whereIn('id', $departmentIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
                if (($departments[$data['department_id']]->status ?? null) !== 'ACTIVE' && ($id === null || $old->department_id != $data['department_id'] || $data['status'] === 'ACTIVE')) {
                    $this->fail('department_id', '科室未启用。');
                }
                $now = now('UTC')->format('Y-m-d H:i:s.v');
                if ($id === null) {
                    $userId = DB::table('users')->insertGetId(['username' => $data['username'], 'password_hash' => Hash::make($data['password']), 'role' => 'DOCTOR', 'status' => 'ACTIVE', 'created_at' => $now, 'updated_at' => $now]);
                    unset($data['username'], $data['password']);
                    $id = DB::table('doctors')->insertGetId($data + ['user_id' => $userId, 'created_at' => $now, 'updated_at' => $now]);
                    $before = null;
                } else {
                    $locked = DB::table('doctors')->where('id', $id)->lockForUpdate()->first();
                    if ($locked->department_id != $old->department_id) {
                        $this->fail('department_id', '医生归属已变化，请重试。');
                    }
                    if ($locked->department_id != $data['department_id']) {
                        $existingSchedules = DB::table('schedules')->where('doctor_id', $id)->lockForUpdate()->get();
                        if ($existingSchedules->isNotEmpty()) {
                            $this->fail('department_id', '已有排班的医生不能转科。');
                        }
                    }
                    $before = ['department_id' => $locked->department_id, 'status' => $locked->status];
                    DB::table('doctors')->where('id', $id)->update($data + ['updated_at' => $now]);
                }
                $this->audit('doctors', $id, 'DOCTOR_SAVED', $before, ['department_id' => (int) $data['department_id'], 'status' => $data['status']]);
            }, 3);
        } catch (UniqueConstraintViolationException $e) {
            $this->fail('username', '该账号、编码或名称已被使用。');
        }

        return redirect()->route('admin.resources')->with('status', '资源已保存。');
    }

    public function storeSchedule(Request $request)
    {
        return $this->schedule($request, null);
    }

    public function updateSchedule(Request $request, int $id)
    {
        return $this->schedule($request, $id);
    }

    private function schedule(Request $request, ?int $id)
    {
        $data = $request->validate([
            'doctor_id' => ['required', 'integer', 'exists:doctors,id'], 'work_date' => ['required', 'date_format:Y-m-d'],
            'session_code' => ['required', Rule::in(['MORNING', 'AFTERNOON', 'EVENING'])],
            'starts_at' => ['required', 'date_format:Y-m-d\TH:i'], 'ends_at' => ['required', 'date_format:Y-m-d\TH:i'],
            'status' => ['required', Rule::in(['DRAFT', 'OPEN', 'SUSPENDED', 'CLOSED'])],
            'suspend_reason' => ['nullable', 'required_if:status,SUSPENDED', 'string', 'max:255'],
        ]);
        $start = CarbonImmutable::createFromFormat('!Y-m-d\TH:i', $data['starts_at'], 'Asia/Shanghai');
        $end = CarbonImmutable::createFromFormat('!Y-m-d\TH:i', $data['ends_at'], 'Asia/Shanghai');
        if ($start->toDateString() !== $data['work_date'] || $end->toDateString() !== $data['work_date'] || $end <= $start) {
            $this->fail('starts_at', '排班须在同一本地日期内且结束晚于开始。');
        }
        $data['starts_at'] = $start->utc()->format('Y-m-d H:i:s.v');
        $data['ends_at'] = $end->utc()->format('Y-m-d H:i:s.v');
        $data['suspend_reason'] = $data['status'] === 'SUSPENDED' ? trim($data['suspend_reason']) : null;
        if ($data['status'] === 'SUSPENDED' && $data['suspend_reason'] === '') {
            $this->fail('suspend_reason', '请填写停诊原因。');
        }
        DB::transaction(function () use ($data, $id): void {
            $old = $id === null ? null : DB::table('schedules')->where('id', $id)->first();
            if ($id !== null) {
                abort_unless($old !== null, 404);
            }
            if ($old && $old->doctor_id != $data['doctor_id']) {
                $this->fail('doctor_id', '不能更改排班医生。');
            }
            $doctor = DB::table('doctors')->where('id', $data['doctor_id'])->first();
            abort_unless($doctor !== null, 404);
            $department = DB::table('departments')->where('id', $doctor->department_id)->lockForUpdate()->first();
            $lockedDoctor = DB::table('doctors')->where('id', $doctor->id)->lockForUpdate()->first();
            if ($lockedDoctor->department_id != $doctor->department_id) {
                $this->fail('doctor_id', '医生归属已变化，请重试。');
            }
            $account = DB::table('users')->where('id', $lockedDoctor->user_id)->sharedLock()->first();
            if (($department->status !== 'ACTIVE' || $lockedDoctor->status !== 'ACTIVE' || $account->role !== 'DOCTOR' || $account->status !== 'ACTIVE') && ($id === null || $data['status'] === 'OPEN')) {
                $this->fail('doctor_id', '科室或医生未启用。');
            }
            $locked = $id === null ? null : DB::table('schedules')->where('id', $id)->lockForUpdate()->first();
            $now = now('UTC')->format('Y-m-d H:i:s.v');
            if ($id === null && $data['starts_at'] <= $now) {
                $this->fail('starts_at', '新排班须从未来开始。');
            }
            if ($data['status'] === 'OPEN' && $data['ends_at'] <= $now) {
                $this->fail('ends_at', '已结束的排班不能开放。');
            }
            if ($locked && ($locked->doctor_id != $doctor->id || $locked->department_id != $doctor->department_id)) {
                $this->fail('doctor_id', '历史排班归属不能更改。');
            }
            $conflictingSchedules = DB::table('schedules')->where('doctor_id', $doctor->id)->when($id, fn ($q) => $q->where('id', '<>', $id))->where(function ($q) use ($data) {
                $q->where(function ($q) use ($data) {
                    $q->where('starts_at', '<', $data['ends_at'])->where('ends_at', '>', $data['starts_at']);
                })->orWhere(function ($q) use ($data) {
                    $q->where('work_date', $data['work_date'])->where('session_code', $data['session_code']);
                });
            })->lockForUpdate()->get();
            if ($conflictingSchedules->isNotEmpty()) {
                $this->fail('starts_at', '医生排班冲突或班次重复。');
            }
            if ($locked) {
                $slots = DB::table('slots')->where('schedule_id', $id)->lockForUpdate()->get();
                $identityChanged = $locked->work_date !== $data['work_date'] || $locked->session_code !== $data['session_code'] || $locked->starts_at !== $data['starts_at'] || $locked->ends_at !== $data['ends_at'];
                if ($identityChanged && ($locked->starts_at <= $now || $data['starts_at'] <= $now)) {
                    $this->fail('starts_at', '已开始的排班不能修改时间，新的开始时间须在未来。');
                }
                if ($identityChanged) {
                    $historicalAppointments = DB::table('appointments')->whereIn('slot_id', $slots->pluck('id'))->lockForUpdate()->get();
                    if ($historicalAppointments->isNotEmpty()) {
                        $this->fail('starts_at', '已有预约的排班时间和班次不可更改。');
                    }
                }
                foreach ($slots as $slot) {
                    if ($slot->starts_at < $data['starts_at'] || $slot->ends_at > $data['ends_at']) {
                        $this->fail('starts_at', '排班须包含所有号源。');
                    }
                }
            }
            $now = now('UTC')->format('Y-m-d H:i:s.v');
            $before = $locked ? ['status' => $locked->status, 'starts_at' => $locked->starts_at, 'ends_at' => $locked->ends_at] : null;
            if ($id === null) {
                $id = DB::table('schedules')->insertGetId($data + ['department_id' => $doctor->department_id, 'created_by' => auth()->id(), 'created_at' => $now, 'updated_at' => $now]);
            } else {
                DB::table('schedules')->where('id', $id)->update($data + ['updated_at' => $now]);
            }
            $this->audit('schedules', $id, 'SCHEDULE_SAVED', $before, ['status' => $data['status'], 'starts_at' => $data['starts_at'], 'ends_at' => $data['ends_at'], 'suspend_reason' => $data['suspend_reason']]);
        }, 3);

        return redirect()->route('admin.resources')->with('status', '资源已保存。');
    }

    public function storeSlot(Request $request)
    {
        return $this->slot($request, null);
    }

    public function updateSlot(Request $request, int $id)
    {
        return $this->slot($request, $id);
    }

    private function slot(Request $request, ?int $id)
    {
        $data = $request->validate([
            'schedule_id' => ['required', 'integer', 'exists:schedules,id'], 'starts_at' => ['required', 'date_format:Y-m-d\TH:i'],
            'ends_at' => ['required', 'date_format:Y-m-d\TH:i'], 'capacity' => ['required', 'integer', 'min:1', 'max:2147483647'],
            'fee_amount' => ['required', 'regex:/^(?:0|[1-9][0-9]{0,7})\.[0-9]{2}$/', 'numeric', 'gt:0'],
            'status' => ['required', Rule::in(['OPEN', 'CLOSED'])],
        ]);
        foreach (['starts_at', 'ends_at'] as $field) {
            $data[$field] = CarbonImmutable::createFromFormat('!Y-m-d\TH:i', $data[$field], 'Asia/Shanghai')->utc()->format('Y-m-d H:i:s.v');
        }
        if ($data['ends_at'] <= $data['starts_at']) {
            $this->fail('ends_at', '结束时间须晚于开始时间。');
        }
        DB::transaction(function () use ($data, $id): void {
            $old = $id === null ? null : DB::table('slots')->where('id', $id)->first();
            if ($id !== null) {
                abort_unless($old !== null, 404);
            }
            if ($old && $old->schedule_id != $data['schedule_id']) {
                $this->fail('schedule_id', '不能更改号源所属排班。');
            }
            $schedule = DB::table('schedules')->where('id', $data['schedule_id'])->first();
            abort_unless($schedule !== null, 404);
            $department = DB::table('departments')->where('id', $schedule->department_id)->lockForUpdate()->first();
            $doctor = DB::table('doctors')->where('id', $schedule->doctor_id)->lockForUpdate()->first();
            $schedule = DB::table('schedules')->where('id', $schedule->id)->lockForUpdate()->first();
            if ($schedule->department_id != $department->id || $schedule->doctor_id != $doctor->id) {
                $this->fail('schedule_id', '排班归属已变化，请重试。');
            }
            $account = DB::table('users')->where('id', $doctor->user_id)->sharedLock()->first();
            if (($department->status !== 'ACTIVE' || $doctor->status !== 'ACTIVE' || $account->role !== 'DOCTOR' || $account->status !== 'ACTIVE' || ! in_array($schedule->status, ['DRAFT', 'OPEN'], true)) && ($id === null || $data['status'] === 'OPEN')) {
                $this->fail('schedule_id', '上级资源未开放。');
            }
            $locked = $id === null ? null : DB::table('slots')->where('id', $id)->lockForUpdate()->first();
            $now = now('UTC')->format('Y-m-d H:i:s.v');
            if ($id === null && $data['starts_at'] <= $now) {
                $this->fail('starts_at', '新号源须从未来开始。');
            }
            if ($locked && $locked->schedule_id != $schedule->id) {
                $this->fail('schedule_id', '号源归属已变化，请重试。');
            }
            if ($data['starts_at'] < $schedule->starts_at || $data['ends_at'] > $schedule->ends_at) {
                $this->fail('starts_at', '号源须在排班内。');
            }
            $overlappingSlots = DB::table('slots')->where('schedule_id', $schedule->id)->when($id, fn ($q) => $q->where('id', '<>', $id))->where('starts_at', '<', $data['ends_at'])->where('ends_at', '>', $data['starts_at'])->lockForUpdate()->get();
            if ($overlappingSlots->isNotEmpty()) {
                $this->fail('starts_at', '号源时段重叠。');
            }
            if ($locked) {
                if (($locked->starts_at !== $data['starts_at'] || $locked->ends_at !== $data['ends_at']) && ($locked->starts_at <= $now || $data['starts_at'] <= $now)) {
                    $this->fail('starts_at', '已开始的号源不能修改时间，新的开始时间须在未来。');
                }
                if ($locked->starts_at !== $data['starts_at'] || $locked->ends_at !== $data['ends_at']) {
                    $historicalAppointments = DB::table('appointments')->where('slot_id', $id)->lockForUpdate()->get();
                    if ($historicalAppointments->isNotEmpty()) {
                        $this->fail('starts_at', '已有预约的号源时间不可更改。');
                    }
                }
                if ($data['capacity'] < $locked->occupied_count) {
                    $this->fail('capacity', '容量不能小于已占用数。');
                }
                if ((int) $data['capacity'] !== $locked->capacity && $locked->starts_at <= now('UTC')->format('Y-m-d H:i:s.v')) {
                    $this->fail('capacity', '时段开始后不能修改容量。');
                }
            }
            $now = now('UTC')->format('Y-m-d H:i:s.v');
            $before = $locked ? ['starts_at' => $locked->starts_at, 'ends_at' => $locked->ends_at, 'capacity' => $locked->capacity, 'fee_amount' => $locked->fee_amount, 'status' => $locked->status] : null;
            if ($id === null) {
                $id = DB::table('slots')->insertGetId($data + ['occupied_count' => 0, 'created_at' => $now, 'updated_at' => $now]);
            } else {
                DB::table('slots')->where('id', $id)->update($data + ['updated_at' => $now]);
            }
            $this->audit('slots', $id, 'SLOT_SAVED', $before, array_intersect_key($data, array_flip(['starts_at', 'ends_at', 'capacity', 'fee_amount', 'status'])));
        }, 3);

        return redirect()->route('admin.resources')->with('status', '资源已保存。');
    }
}
