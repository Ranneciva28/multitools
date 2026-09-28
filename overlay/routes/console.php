<?php
use Illuminate\Support\Facades\Schedule;
Schedule::command('threads:dispatch-due')->everyMinute()->withoutOverlapping();
