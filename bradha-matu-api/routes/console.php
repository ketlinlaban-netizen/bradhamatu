<?php

use App\Services\ExpiryService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Process expired service accounts — every 5 minutes
Artisan::command('billing:process-expired', function (ExpiryService $service) {
    $count = $service->processExpiredAccounts();
    $this->info("Processed {$count} expired accounts.");
})->purpose('Deactivate expired service accounts');

// Retry failed activations — every 10 minutes
Artisan::command('billing:retry-activations', function (ExpiryService $service) {
    $count = $service->retryFailedActivations();
    $this->info("Retried {$count} failed activations.");
})->purpose('Retry failed hotspot activations');

// Reconcile stale pending payments — every 5 minutes
Artisan::command('billing:reconcile-payments', function (ExpiryService $service) {
    $count = $service->reconcileStalePayments();
    $this->info("Reconciled {$count} stale payments.");
})->purpose('Query Daraja for stale pending payments');

// Activate purchases with verified payments not yet activated — every 2 minutes
Artisan::command('billing:process-activations', function (ExpiryService $service) {
    $count = $service->processPendingActivations();
    $this->info("Processed {$count} pending activations.");
})->purpose('Activate purchases with verified payments');
