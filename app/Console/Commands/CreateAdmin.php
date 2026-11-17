<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class CreateAdmin extends Command
{
    protected $signature = 'mediqueue:create-admin {username : New administrator username}';

    protected $description = 'Create a local/demo administrator through hidden interactive password input';

    public function handle(): int
    {
        if (! app()->environment(['local', 'demo', 'testing'])) {
            $this->error('管理员初始化命令仅允许在 local、demo、testing 环境运行。');

            return self::FAILURE;
        }
        if (! $this->input->isInteractive()) {
            $this->error('请在交互终端中使用隐藏密码输入；不接受命令行密码参数。');

            return self::FAILURE;
        }

        $username = User::normalizeUsername($this->argument('username'));
        $validation = Validator::make(['username' => $username], [
            'username' => [...User::usernameRules(), Rule::unique('users', 'username')],
        ]);
        if ($validation->fails()) {
            $this->error('账号须为3至64位字母、数字或下划线，且不能与现有账号重复。');

            return self::FAILURE;
        }

        $password = $this->secret('输入管理员密码（至少12字符，最多72字节）', false);
        $confirmation = $this->secret('再次输入管理员密码', false);
        $validation = Validator::make(['password' => $password, 'password_confirmation' => $confirmation], [
            'password' => ['required', 'string', Password::defaults(), 'confirmed'],
        ]);
        if ($validation->fails()) {
            $this->error('密码不符合要求或两次输入不一致；未创建账号。');

            return self::FAILURE;
        }

        try {
            DB::transaction(function () use ($username, $password): void {
                $user = User::create([
                    'username' => $username, 'password_hash' => Hash::make($password),
                    'role' => 'ADMIN', 'status' => 'ACTIVE',
                ]);
                DB::table('audit_logs')->insert([
                    'actor_type' => 'SYSTEM', 'operator_id' => null,
                    'action' => 'ADMIN_CREATED', 'target_type' => 'users', 'target_id' => $user->id,
                    'after_state' => json_encode(['role' => 'ADMIN', 'status' => 'ACTIVE'], JSON_THROW_ON_ERROR),
                    'created_at' => now('UTC')->format('Y-m-d H:i:s.v'),
                ]);
            });
        } catch (UniqueConstraintViolationException) {
            $this->error('该账号已存在；未覆盖现有账号。');

            return self::FAILURE;
        }

        $this->info('管理员账号已创建；密码未输出。');

        return self::SUCCESS;
    }
}
