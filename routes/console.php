<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('mediqueue:expire-appointments --limit=100')->everyMinute()->withoutOverlapping();
