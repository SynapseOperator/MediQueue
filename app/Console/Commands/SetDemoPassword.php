<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

class SetDemoPassword extends Command
{
    protected $signature = 'mediqueue:demo-password {username : Existing seeded demo account}';

    protected $description = 'Set a demo password through hidden interactive input without clearing business data';

    public function handle(): int
    {
        if (! app()->environment(['local', 'demo', 'testing'])) {
            $this->error('演示密码命令仅允许在 local、demo、testing 环境运行。');

            return self::FAILURE;
        }

        $username = User::normalizeUsername($this->argument('username'));
        if (! in_array($username, ['demo_admin', 'demo_doctor_1', 'demo_doctor_2', 'demo_patient_1', 'demo_patient_2', 'demo_patient_3'], true)
            || ! User::where('username', $username)->exists()) {
            $this->error('只能更新已经存在的合成演示账号；本命令不会初始化或清空数据。');

            return self::FAILURE;
        }
        if (! $this->input->isInteractive()) {
            $this->error('请在交互终端中使用隐藏密码输入；不接受命令行密码参数。');

            return self::FAILURE;
        }

        $password = $this->secret('输入新密码（至少12字符，最多72字节）', false);
        $confirmation = $this->secret('再次输入新密码', false);
        $validation = Validator::make(['password' => $password, 'password_confirmation' => $confirmation], [
            'password' => ['required', 'string', Password::defaults(), 'confirmed'],
        ]);
        if ($validation->fails()) {
            $this->error('密码不符合要求或两次输入不一致；账号未修改。');

            return self::FAILURE;
        }

        DB::transaction(function () use ($username, $password): void {
            $user = User::where('username', $username)->lockForUpdate()->firstOrFail();
            $user->password_hash = Hash::make($password);
            $user->save();
            DB::table('sessions')->where('user_id', $user->id)->delete();
            DB::table('audit_logs')->insert([
                'actor_type' => 'SYSTEM', 'operator_id' => null,
                'action' => 'DEMO_PASSWORD_RESET', 'target_type' => 'users', 'target_id' => $user->id,
                'after_state' => json_encode(['password_changed' => true], JSON_THROW_ON_ERROR),
                'created_at' => now('UTC')->format('Y-m-d H:i:s.v'),
            ]);
        });

        $this->info('演示密码已更新；旧会话已失效，业务数据未清空。');

        return self::SUCCESS;
    }
}
