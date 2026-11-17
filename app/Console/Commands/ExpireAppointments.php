<?php

namespace App\Console\Commands;

use App\Services\AppointmentLifecycleService;
use Illuminate\Console\Command;

class ExpireAppointments extends Command
{
    protected $signature = 'mediqueue:expire-appointments {--limit=100 : Maximum candidates in this batch (1-1000)}';

    protected $description = 'Reclaim due unpaid appointments using the shared patient-first lifecycle transaction';

    public function handle(AppointmentLifecycleService $service): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1000]]);
        if ($limit === false) {
            $this->error('limit 必须为 1–1000。');

            return self::INVALID;
        }
        $this->info('已回收 '.$service->reclaim(limit: $limit).' 条到期预约。');

        return self::SUCCESS;
    }
}
