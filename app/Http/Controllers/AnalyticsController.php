<?php

namespace App\Http\Controllers;

use App\Services\AnalyticsService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class AnalyticsController extends Controller
{
    public const ACTIONS = ['ADMIN_CREATED', 'DEMO_PASSWORD_RESET', 'PATIENT_REGISTERED', 'PATIENT_PROFILE_UPDATED',
        'DEPARTMENT_SAVED', 'DOCTOR_SAVED', 'SCHEDULE_SAVED', 'SLOT_SAVED', 'APPOINTMENT_CREATED',
        'PAYMENT_CONFIRMED', 'PAYMENT_DECLINED', 'PAYMENT_UNKNOWN', 'APPOINTMENT_CANCELLED',
        'APPOINTMENT_EXPIRED', 'APPOINTMENT_COMPLETED', 'REFUND_SUCCEEDED', 'REFUND_FAILED', 'REFUND_PENDING'];

    public const TARGETS = ['users', 'patients', 'departments', 'doctors', 'schedules', 'slots', 'appointments', 'payment_orders', 'refunds'];

    public function dashboard(Request $request, AnalyticsService $service): View
    {
        return $this->report($request, $service, false);
    }

    public function analytics(Request $request, AnalyticsService $service): View
    {
        return $this->report($request, $service, true);
    }

    private function report(Request $request, AnalyticsService $service, bool $detail): View
    {
        $dates = $this->filters($request);

        return view('admin.analytics', $service->report($dates['from'], $dates['to']) + ['detail' => $detail]);
    }

    public function audit(Request $request): View
    {
        $filters = $this->filters($request, [
            'action' => ['nullable', Rule::in(self::ACTIONS)], 'target_type' => ['nullable', Rule::in(self::TARGETS)],
            'actor_type' => ['nullable', Rule::in(['USER', 'SYSTEM'])], 'target_id' => ['nullable', 'integer', 'min:1'],
        ]);
        $timezone = config('mediqueue.hospital_timezone');
        $start = CarbonImmutable::parse($filters['from'], $timezone)->startOfDay()->utc()->format('Y-m-d H:i:s.v');
        $end = CarbonImmutable::parse($filters['to'], $timezone)->addDay()->startOfDay()->utc()->format('Y-m-d H:i:s.v');
        $query = DB::table('audit_logs')->where('created_at', '>=', $start)->where('created_at', '<', $end);
        foreach (['action', 'target_type', 'actor_type', 'target_id'] as $field) {
            if (isset($filters[$field])) {
                $query->where($field, $filters[$field]);
            }
        }
        // Explicit metadata projection: JSON, request bodies and account/person details never leave this query.
        $logs = $query->select('id', 'created_at', 'actor_type', 'operator_id', 'action', 'target_type', 'target_id')
            ->orderByDesc('id')->paginate(20)->appends($filters);

        return view('admin.audit', ['filters' => $filters, 'logs' => $logs, 'actions' => self::ACTIONS, 'targets' => self::TARGETS]);
    }

    private function filters(Request $request, array $extra = []): array
    {
        $today = CarbonImmutable::now(config('mediqueue.hospital_timezone'));

        return Validator::make($request->query() + ['from' => $today->startOfMonth()->toDateString(), 'to' => $today->toDateString()], [
            'from' => ['required', 'date_format:Y-m-d', 'before_or_equal:to'],
            'to' => ['required', 'date_format:Y-m-d'], ...$extra,
        ])->validate();
    }
}
