<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\AiController;
use App\Http\Controllers\AppointmentController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CareController;
use App\Http\Controllers\ChatController;
use App\Http\Controllers\ClinicianController;
use App\Http\Controllers\MediaController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\PublicController;
use App\Http\Controllers\SupportController;
use Illuminate\Support\Facades\Route;

// The API the Tena website calls. Same addresses and answers as the original version. Sign-in is a bearer token (no cookies).

// ---- open to everyone ----
Route::get('/health', [PublicController::class, 'health']);
Route::get('/prices', [ClinicianController::class, 'prices']);
Route::get('/public/doctors', [ClinicianController::class, 'publicDoctors']);
Route::get('/ai/status', [AiController::class, 'status']);
Route::post('/ai/assistant', [AiController::class, 'assistant']);
Route::post('/auth/register', [AuthController::class, 'register']);
Route::post('/auth/login', [AuthController::class, 'login']);
Route::get('/media/{id}', [MediaController::class, 'show']);                       // checks access itself
Route::get('/payments/chapa/callback', [PaymentController::class, 'callback']);
Route::post('/payments/chapa/webhook', [PaymentController::class, 'webhook']);     // checks Chapa's signature itself

// ---- any signed-in person ----
Route::middleware('tena')->group(function () {
    Route::get('/auth/me', [AuthController::class, 'me']);
    Route::post('/auth/mfa/setup', [AuthController::class, 'mfaSetup']);
    Route::post('/auth/mfa/enable', [AuthController::class, 'mfaEnable']);
});

// ---- patients ----
Route::middleware('tena:patient')->group(function () {
    Route::get('/clinicians', [ClinicianController::class, 'index']);
    Route::get('/clinicians/{id}', [ClinicianController::class, 'show'])->whereNumber('id');
    Route::get('/clinicians/{id}/slots', [ClinicianController::class, 'slots'])->whereNumber('id');
    Route::post('/appointments', [AppointmentController::class, 'book']);
    Route::post('/appointments/{aid}/pay', [AppointmentController::class, 'pay']);
    Route::get('/payments/verify/{ref}', [AppointmentController::class, 'verifyPayment']);
    Route::post('/dev/simulate-payment/{aid}', [AppointmentController::class, 'simulate']);
    Route::post('/appointments/{aid}/reschedule', [AppointmentController::class, 'reschedule']);
    Route::post('/appointments/{aid}/photos', [AppointmentController::class, 'addPhoto']);
    Route::delete('/appointments/{aid}/photos/{mid}', [AppointmentController::class, 'deletePhoto']);
    Route::get('/records', [AppointmentController::class, 'records']);
    Route::get('/prescriptions', [AppointmentController::class, 'prescriptions']);
    Route::get('/patient/doctors', [CareController::class, 'myDoctors']);
    Route::match(['get', 'put'], '/patient/profile', [CareController::class, 'patientProfile']);
});

// ---- doctors ----
Route::middleware('tena:clinician')->group(function () {
    Route::match(['get', 'put'], '/clinician/profile', [ClinicianController::class, 'profile']);
    Route::post('/clinician/photo', [ClinicianController::class, 'photo']);
    Route::post('/clinician/profile/submit', [ClinicianController::class, 'submit']);
    Route::get('/clinician/slots', [ClinicianController::class, 'mySlots']);
    Route::post('/clinician/slots', [ClinicianController::class, 'addSlot']);
    Route::delete('/clinician/slots/{id}', [ClinicianController::class, 'deleteSlot'])->whereNumber('id');
    Route::get('/clinician/patients', [CareController::class, 'myPatients']);
    Route::get('/clinician/patients/{pid}', [CareController::class, 'patientDetail'])->whereNumber('pid');
    Route::post('/appointments/{aid}/no-show', [AppointmentController::class, 'noShow']);
    Route::match(['get', 'put'], '/appointments/{aid}/note', [AppointmentController::class, 'note']);
    Route::post('/appointments/{aid}/note/finalize', [AppointmentController::class, 'finalize']);
    Route::post('/appointments/{aid}/note/amend', [AppointmentController::class, 'amend']);
    Route::post('/appointments/{aid}/prescriptions', [AppointmentController::class, 'prescribe']);
    Route::post('/appointments/{aid}/ai/draft-note', [AiController::class, 'draftNote']);
    Route::post('/appointments/{aid}/ai/copilot', [AiController::class, 'copilot']);
    Route::post('/appointments/{aid}/ai/suggested-questions', [AiController::class, 'suggestedQuestions']);
    Route::post('/appointments/{aid}/ai/translate', [AiController::class, 'translate']);
    Route::post('/appointments/{aid}/ai/post-call-actions', [AiController::class, 'postCall']);
    Route::post('/appointments/{aid}/ai/transcribe', [AiController::class, 'transcribe']);
    Route::get('/ai/reference-lookup', [AiController::class, 'references']);
});

// ---- patients and doctors ----
Route::middleware('tena:patient,clinician')->group(function () {
    Route::post('/consent', [AuthController::class, 'consent']);
    Route::get('/appointments', [AppointmentController::class, 'index']);
    Route::get('/appointments/{aid}', [AppointmentController::class, 'show']);
    Route::post('/appointments/{aid}/cancel', [AppointmentController::class, 'cancel']);
    Route::get('/appointments/{aid}/room', [AppointmentController::class, 'room']);
    Route::match(['get', 'put'], '/appointments/{aid}/intake', [AppointmentController::class, 'intake']);
    Route::post('/appointments/{aid}/ai/connection-help', [AiController::class, 'connectionHelp']);
    Route::get('/conversations', [ChatController::class, 'conversations']);
    Route::get('/conversations/{cid}/messages', [ChatController::class, 'messages'])->whereNumber('cid');
    Route::post('/conversations/{cid}/messages', [ChatController::class, 'send'])->whereNumber('cid');
    Route::post('/tickets', [SupportController::class, 'create']);
    Route::post('/ai/ticket-draft', [AiController::class, 'ticketDraft']);
});

// ---- support, compliance and admin ----
Route::middleware('tena:patient,clinician,support,compliance,admin')->get('/tickets', [SupportController::class, 'index']);
Route::middleware('tena:support,compliance,admin')->put('/tickets/{id}', [SupportController::class, 'update'])->whereNumber('id');

Route::middleware('tena:admin')->prefix('admin')->group(function () {
    Route::get('/clinicians', [AdminController::class, 'clinicians']);
    Route::post('/clinicians/{cid}/review', [AdminController::class, 'review'])->whereNumber('cid');
    Route::get('/audit', [AdminController::class, 'audit']);
    Route::match(['get', 'put'], '/config', [AdminController::class, 'config']);
    Route::post('/staff', [AdminController::class, 'addStaff']);
    Route::get('/notifications', [AdminController::class, 'notifications']);
    Route::post('/integrations-test', [AdminController::class, 'integrationsTest']);
});
Route::middleware('tena:admin,support')->get('/admin/appointments', [AdminController::class, 'appointments']);
Route::middleware('tena:admin,finance')->group(function () {
    Route::post('/admin/ai/analytics-summary', [AiController::class, 'analytics']);
    Route::get('/finance/payouts', [SupportController::class, 'payouts']);
    Route::post('/finance/payouts/{id}/paid', [SupportController::class, 'payoutPaid'])->whereNumber('id');
    Route::get('/finance/refunds', [SupportController::class, 'refunds']);
    Route::post('/finance/refunds/{id}/retry', [SupportController::class, 'retryRefund'])->whereNumber('id');
    Route::post('/finance/refunds/{id}/mark-refunded', [SupportController::class, 'markRefunded'])->whereNumber('id');
    Route::get('/reports', [SupportController::class, 'reports']);
});
