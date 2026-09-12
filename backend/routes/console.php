<?php

use App\Models\Otp;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('otps:prune {--hours=24 : Delete OTPs older than this number of hours}', function () {
    $hours = (int) $this->option('hours') ?: 24;
    $count = Otp::where('created_at', '<', now()->subHours($hours))->delete();
    $this->info("Pruned {$count} expired/stale OTP records older than {$hours} hours.");
})->purpose('Prune expired and stale OTP verification records from the database');

Schedule::command('otps:prune')->daily();
