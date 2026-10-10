<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\SmsMessage;
use App\Models\AppSetting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class SmsService
{
    public const TEMPLATES = [
        'welcome' => 'Welcome to Bradha Matu WiFi, [[name]]! Your account is ready. Dial *123# to buy data.',
        'payment_confirmation' => 'Payment confirmed: KSh [[amount]] for [[package]]. Active until [[expiration]]. Invoice #[[invoice]].',
        'expiry_reminder' => 'Hi [[name]], your [[package]] plan expires in [[hours]] hours. Top up to stay connected.',
        'expiry_notification' => 'Your [[package]] plan has expired. Visit our portal to renew and reconnect.',
        'voucher_purchase' => 'Voucher code [[code]] activated for [[package]]. Valid for [[duration]] minutes.',
        'activation_success' => 'You are connected! Username: [[username]]. Plan expires on [[expiration]].',
    ];

    public function send(Customer $customer, string $template, array $variables = []): SmsMessage
    {
        $body = $this->renderTemplate($template, $variables);

        $message = SmsMessage::create([
            'id' => Str::uuid()->toString(),
            'customer_id' => $customer->id,
            'to_phone' => $customer->phone_display,
            'template' => $template,
            'body' => $body,
            'status' => SmsMessage::STATUS_QUEUED,
            'variables' => $variables,
        ]);

        $this->deliver($message);
        return $message;
    }

    public function sendToPhone(string $phone, string $template, array $variables = []): SmsMessage
    {
        $body = $this->renderTemplate($template, $variables);

        $message = SmsMessage::create([
            'id' => Str::uuid()->toString(),
            'customer_id' => null,
            'to_phone' => $phone,
            'template' => $template,
            'body' => $body,
            'status' => SmsMessage::STATUS_QUEUED,
            'variables' => $variables,
        ]);

        $this->deliver($message);
        return $message;
    }

    public function renderTemplate(string $template, array $variables): string
    {
        $text = self::TEMPLATES[$template] ?? $template;
        foreach ($variables as $key => $value) {
            $text = str_replace("[[{$key}]]", (string) $value, $text);
        }
        return $text;
    }

    private function deliver(SmsMessage $message): void
    {
        $endpoint = AppSetting::get('sms_endpoint');
        $apiKey = AppSetting::get('sms_api_key');
        $senderId = AppSetting::get('sms_sender_id', 'BradhaMatu');

        if (!$endpoint || !$apiKey) {
            Log::info('SMS not delivered — no gateway configured', [
                'sms_id' => $message->id,
                'template' => $message->template,
            ]);
            return;
        }

        try {
            $response = Http::withToken($apiKey)
                ->post($endpoint, [
                    'sender' => $senderId,
                    'to' => $message->to_phone,
                    'message' => $message->body,
                ]);

            if ($response->successful()) {
                $message->update([
                    'status' => SmsMessage::STATUS_SENT,
                    'sent_at' => now(),
                ]);
            } else {
                $message->update([
                    'status' => SmsMessage::STATUS_FAILED,
                    'error' => 'HTTP ' . $response->status() . ': ' . $response->body(),
                ]);
            }
        } catch (\Throwable $e) {
            $message->update([
                'status' => SmsMessage::STATUS_FAILED,
                'error' => $e->getMessage(),
            ]);
            Log::error('SMS delivery failed', [
                'sms_id' => $message->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
