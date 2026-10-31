<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('users', function (Blueprint $t) {
            $t->id();
            $t->string('email', 120)->unique();
            $t->string('phone', 30)->nullable();
            $t->string('name', 120);
            $t->string('password_hash', 255);
            $t->string('role', 20);                 // patient|clinician|admin|support|finance|compliance
            $t->string('consent_version', 10)->nullable();
            $t->dateTime('consent_at')->nullable();
            $t->text('mfa_secret')->nullable();     // stored encrypted
            $t->boolean('mfa_on')->default(false);
            $t->unsignedInteger('token_version')->default(1);
        });

        Schema::create('clinicians', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $t->string('specialty', 80)->default('Dermatology');
            $t->string('language', 80)->default('Amharic, English');
            $t->string('reg_number', 60)->nullable();
            $t->string('status', 20)->default('draft');   // draft|pending|approved|rejected
            $t->unsignedBigInteger('reviewed_by')->nullable();
            $t->string('review_reason', 255)->nullable();
            $t->text('bio')->nullable();
            $t->string('conditions', 400)->nullable();
            $t->unsignedInteger('years_exp')->nullable();
            $t->string('photo_id', 32)->nullable();
            $t->dateTime('submitted_at')->nullable();
        });

        Schema::create('slots', function (Blueprint $t) {
            $t->id();
            $t->foreignId('clinician_id')->constrained('clinicians')->cascadeOnDelete();
            $t->dateTime('starts_at', 6);
            $t->unsignedInteger('duration_min')->default(30);
            $t->boolean('booked')->default(false);
            $t->dateTime('hold_expires_at')->nullable();
            $t->unique(['clinician_id', 'starts_at']);
        });

        Schema::create('appointments', function (Blueprint $t) {
            $t->string('id', 36)->primary();
            $t->foreignId('slot_id')->constrained('slots');
            $t->foreignId('patient_id')->constrained('users');
            $t->foreignId('clinician_id')->constrained('clinicians');
            $t->string('reason', 500)->nullable();
            $t->string('status', 20)->default('pending_payment');
            $t->string('room', 64);
            $t->boolean('reminded')->default(false);
            $t->string('tier', 12)->default('video_chat');   // chat | video_chat
            $t->dateTime('chat_until')->nullable();
            $t->index(['patient_id', 'status']);
            $t->index(['clinician_id', 'status']);
        });

        Schema::create('payments', function (Blueprint $t) {
            $t->id();
            $t->string('appointment_id', 36)->index();
            $t->unsignedInteger('amount');                   // birr
            $t->string('method', 20);
            $t->string('provider_ref', 80)->unique();
            $t->string('status', 20)->default('initiated');  // initiated|paid|failed|refund_pending|refunded|review
            $t->string('refund_ref', 80)->nullable();
            $t->string('refund_id', 80)->nullable();
        });

        Schema::create('notes', function (Blueprint $t) {
            $t->id();
            $t->string('appointment_id', 36)->index();
            $t->longText('assessment')->nullable();
            $t->longText('plan')->nullable();
            $t->longText('summary_for_patient')->nullable();
            $t->boolean('finalized')->default(false);
            $t->dateTime('finalized_at')->nullable();
        });

        Schema::create('intakes', function (Blueprint $t) {
            $t->id();
            $t->string('appointment_id', 36)->unique();
            $t->foreignId('patient_id')->constrained('users');
            $t->longText('answers')->nullable();
            $t->dateTime('updated_at')->nullable();
        });

        Schema::create('amendments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('note_id')->constrained('notes');
            $t->unsignedBigInteger('author_id')->nullable();
            $t->string('reason', 255)->nullable();
            $t->longText('text')->nullable();
            $t->dateTime('created_at')->nullable();
        });

        Schema::create('prescriptions', function (Blueprint $t) {
            $t->id();
            $t->string('appointment_id', 36)->index();
            $t->string('medicine', 120);
            $t->string('dose', 60);
            $t->string('route', 40);
            $t->string('frequency', 60);
            $t->string('duration', 60);
            $t->string('quantity', 40);
            $t->string('instructions', 255)->nullable();
            $t->unsignedBigInteger('prescriber_id')->nullable();
            $t->dateTime('issued_at')->nullable();
        });

        Schema::create('audits', function (Blueprint $t) {
            $t->id();
            $t->dateTime('at')->nullable();
            $t->unsignedBigInteger('user_id')->nullable();
            $t->string('action', 80);
            $t->string('detail', 255)->nullable();
        });

        Schema::create('media', function (Blueprint $t) {
            $t->string('id', 32)->primary();
            $t->unsignedBigInteger('owner_id')->nullable();
            $t->string('kind', 12);                          // profile | visit | chat
            $t->string('mime', 30);
            $t->boolean('encrypted')->default(false);
            $t->dateTime('created_at')->nullable();
        });

        Schema::create('appointment_photos', function (Blueprint $t) {
            $t->id();
            $t->string('appointment_id', 36)->index();
            $t->string('media_id', 32)->index();
            $t->string('caption', 120)->nullable();
        });

        Schema::create('conversations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('patient_id')->constrained('users');
            $t->foreignId('clinician_id')->constrained('clinicians');
            $t->unique(['patient_id', 'clinician_id']);
        });

        Schema::create('messages', function (Blueprint $t) {
            $t->id();
            $t->foreignId('conversation_id')->constrained('conversations');
            $t->foreignId('sender_id')->constrained('users');
            $t->longText('body')->nullable();
            $t->string('media_id', 32)->nullable();
            $t->dateTime('created_at')->nullable();
            $t->dateTime('read_at')->nullable();
        });

        Schema::create('patient_profiles', function (Blueprint $t) {
            $t->unsignedBigInteger('user_id')->primary();
            $t->longText('data')->nullable();
            $t->dateTime('updated_at')->nullable();
        });

        Schema::create('configs', function (Blueprint $t) {
            $t->string('key', 40)->primary();
            $t->string('value', 200)->nullable();
        });

        Schema::create('notifications', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id')->nullable();
            $t->string('kind', 30);
            $t->string('body', 300);
            $t->string('status', 20);
            $t->dateTime('at')->nullable();
        });

        Schema::create('tickets', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained('users');
            $t->string('kind', 20);
            $t->string('subject', 150);
            $t->text('body')->nullable();
            $t->string('status', 20)->default('open');
            $t->text('resolution')->nullable();
            $t->dateTime('due_at')->nullable();
            $t->dateTime('created')->nullable();
        });

        Schema::create('payouts', function (Blueprint $t) {
            $t->id();
            $t->string('appointment_id', 36)->unique();
            $t->foreignId('clinician_id')->constrained('clinicians');
            $t->unsignedInteger('gross');
            $t->unsignedInteger('commission');
            $t->unsignedInteger('net');
            $t->string('status', 10)->default('pending');
        });

        Schema::create('ai_outputs', function (Blueprint $t) {
            $t->id();
            $t->string('appointment_id', 36)->nullable()->index();
            $t->string('feature', 30);
            $t->string('model', 60)->nullable();
            $t->longText('draft')->nullable();
            $t->string('status', 10)->default('draft');
            $t->unsignedBigInteger('approved_by')->nullable();
            $t->dateTime('approved_at')->nullable();
        });
    }

    public function down(): void
    {
        foreach (['ai_outputs', 'payouts', 'tickets', 'notifications', 'configs', 'patient_profiles', 'messages', 'conversations',
                  'appointment_photos', 'media', 'audits', 'prescriptions', 'amendments', 'intakes', 'notes', 'payments',
                  'appointments', 'slots', 'clinicians', 'users'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
