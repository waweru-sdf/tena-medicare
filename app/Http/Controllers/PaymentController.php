<?php

namespace App\Http\Controllers;

use App\Models\Payment;
use App\Services\Billing;
use App\Services\Chapa;
use Illuminate\Http\Request;

class PaymentController extends Controller
{
    /** Chapa sends the patient's browser here. It is only a signal: the website then asks us to verify with Chapa. */
    public function callback(Request $r)
    {
        $ref = $r->query('ref') ?: $r->query('trx_ref');
        if (!$ref) {
            return $this->err('Missing transaction reference.');
        }
        return response()->json(['message' => 'Return received. Sign in and verify the payment.', 'provider_ref' => $ref]);
    }

    public function webhook(Request $r)
    {
        $raw = $r->getContent();
        $headers = ['x-chapa-signature' => $r->header('x-chapa-signature', ''), 'chapa-signature' => $r->header('Chapa-Signature', '')];
        if (!Chapa::enabled() || !Chapa::signatureOk($raw, $headers)) {
            return $this->err('forbidden', 403);
        }
        $body = json_decode($raw, true) ?: [];
        $ref = $body['tx_ref'] ?? $body['merchant_reference'] ?? $body['payment_reference'] ?? $body['reference'] ?? null;
        $p = $ref ? Payment::where('provider_ref', (string) $ref)->first() : null;
        if (!$p) {
            return response()->json(['ok' => true]);
        }
        try {
            if ($p->status === 'refund_pending') {
                Billing::syncRefund($p);
            } elseif ($p->status === 'initiated') {
                $v = Chapa::verify($p->provider_ref);
                $d = $v['data'];
                if ($v['ok'] && ($d['status'] ?? '') === 'success') {
                    Billing::markPaid($p, $d['amount'] ?? null, $d['currency'] ?? '');
                }
            }
        } catch (\Throwable) {
            return $this->err('verification unavailable', 503);
        }
        return response()->json(['ok' => true]);
    }
}
