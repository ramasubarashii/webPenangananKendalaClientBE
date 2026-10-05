<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\PushSubscription;
use Symfony\Component\Process\Process;
use Illuminate\Support\Facades\Log;

class PushSubscriptionController extends Controller
{
    /**
     * Get Public VAPID Key
     */
    public function getPublicKey()
    {
        return response()->json([
            'publicKey' => env('VAPID_PUBLIC_KEY', 'BCoDaIqs1n_d3g97zGZxA998RUzN9bKD1gRMKa3qWtqKbwgsubWxBmJNMU5uxqM59Rrtmodvp6ziWyDjiRv0UuQ')
        ]);
    }

    /**
     * Store or update Web Push Subscription
     */
    public function subscribe(Request $request)
    {
        $request->validate([
            'endpoint' => 'required|string',
            'keys.p256dh' => 'nullable|string',
            'keys.auth' => 'nullable|string',
        ]);

        $user = $request->user();

        $subscription = PushSubscription::updateOrCreate(
            ['endpoint' => $request->endpoint],
            [
                'user_id' => $user ? $user->id : null,
                'public_key' => $request->input('keys.p256dh'),
                'auth_token' => $request->input('keys.auth'),
                'content_encoding' => $request->input('encoding', 'aesgcm'),
            ]
        );

        return response()->json([
            'message' => 'Push subscription saved successfully.',
            'subscription' => $subscription
        ], 201);
    }

    /**
     * Send Test Web Push Notification
     */
    public function sendTestPush(Request $request)
    {
        $user = $request->user();
        $subscriptions = PushSubscription::when($user, function($query) use ($user) {
            return $query->where('user_id', $user->id);
        })->get();

        if ($subscriptions->isEmpty()) {
            return response()->json([
                'message' => 'Belum ada browser yang terdaftar untuk push notification. Klik tombol "Aktifkan Izin Web Push" terlebih dahulu di browser Anda.'
            ], 404);
        }

        $vapid = [
            'subject' => env('VAPID_SUBJECT', 'mailto:admin@example.com'),
            'publicKey' => env('VAPID_PUBLIC_KEY', 'BCoDaIqs1n_d3g97zGZxA998RUzN9bKD1gRMKa3qWtqKbwgsubWxBmJNMU5uxqM59Rrtmodvp6ziWyDjiRv0UuQ'),
            'privateKey' => env('VAPID_PRIVATE_KEY', '_MTFLwZXLmu1K08k_UUM9WvXpFA8NJwrXWwBKMRNris'),
        ];

        $payload = json_encode([
            'title' => '🔔 Notifikasi Sistem Kendala Client',
            'body' => 'Web Push Notification real-time Phase 3 berhasil diaktifkan!',
            'icon' => '/pwa-192x192.png',
            'badge' => '/pwa-192x192.png',
            'data' => [
                'url' => '/client',
                'timestamp' => now()->toIso8601String()
            ]
        ]);

        $sentCount = 0;
        $scriptPath = base_path('scripts/send_push.cjs');

        foreach ($subscriptions as $sub) {
            $subData = [
                'endpoint' => $sub->endpoint,
                'keys' => [
                    'p256dh' => $sub->public_key,
                    'auth' => $sub->auth_token,
                ]
            ];

            $process = new Process([
                'node',
                $scriptPath,
                json_encode($subData),
                $payload,
                json_encode($vapid)
            ]);

            $process->run();

            if ($process->isSuccessful()) {
                $sentCount++;
            } else {
                Log::warning("[WebPush] Target failed: " . $process->getErrorOutput());
            }
        }

        return response()->json([
            'message' => "Web Push notification berhasil dikirim ke {$sentCount} perangkat aktif!",
            'sent_count' => $sentCount
        ]);
    }
}
