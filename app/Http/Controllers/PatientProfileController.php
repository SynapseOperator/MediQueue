<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class PatientProfileController extends Controller
{
    public function edit(Request $request): View
    {
        $patient = DB::table('patients')->where('user_id', $request->user()->id)->first();
        abort_unless($patient !== null, 403);

        return view('patient.profile', ['patient' => $patient, 'user' => $request->user()]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'full_name' => ['required', 'string', 'max:64'],
            'phone' => ['required', 'string', 'max:32'],
            'id' => ['prohibited'], 'patient_id' => ['prohibited'], 'user_id' => ['prohibited'],
            'role' => ['prohibited'], 'status' => ['prohibited'],
        ]);

        DB::transaction(function () use ($request, $data): void {
            $patient = DB::table('patients')->where('user_id', $request->user()->id)->lockForUpdate()->first();
            abort_unless($patient !== null, 403);

            $now = now('UTC')->format('Y-m-d H:i:s.v');
            DB::table('patients')->where('id', $patient->id)->update([
                'full_name' => $data['full_name'], 'phone' => $data['phone'], 'updated_at' => $now,
            ]);
            DB::table('audit_logs')->insert([
                'actor_type' => 'USER', 'operator_id' => $request->user()->id,
                'action' => 'PATIENT_PROFILE_UPDATED', 'target_type' => 'patients', 'target_id' => $patient->id,
                'after_state' => json_encode(['fields' => ['full_name', 'phone']], JSON_THROW_ON_ERROR),
                'created_at' => $now,
            ]);
        });

        return redirect()->route('patient.profile.edit')->with('status', '个人资料已更新。');
    }
}
