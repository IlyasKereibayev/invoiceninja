<?php

/**
 * Invoice Ninja (https://invoiceninja.com).
 *
 * @link https://github.com/invoiceninja/invoiceninja source repository
 *
 * @copyright Copyright (c) 2020. Invoice Ninja LLC (https://invoiceninja.com)
 *
 * @license https://opensource.org/licenses/AAL
 */

namespace App\PaymentDrivers\CheckoutCom;

use App\Exceptions\PaymentFailed;
use App\Jobs\Mail\PaymentFailureMailer;
use App\Jobs\Util\SystemLogger;
use App\Models\GatewayType;
use App\Models\PaymentType;
use App\Models\SystemLog;

trait Utilities
{
    public function getPublishableKey()
    {
        return $this->company_gateway->getConfigField('publicApiKey');
    }

    public function convertToCheckoutAmount($amount, $currency)
    {
        $cases = [
            'option_1' => ['BIF', 'DJF', 'GNF', 'ISK', 'KMF', 'XAF', 'CLF', 'XPF', 'JPY', 'PYG', 'RWF', 'KRW', 'VUV', 'VND', 'XOF'],
            'option_2' => ['BHD', 'IQD', 'JOD', 'KWD', 'LYD', 'OMR', 'TND'],
        ];

        // https://docs.checkout.com/resources/calculating-the-value#Calculatingthevalue-Option1:Thefullvaluefullvalue
        if (in_array($currency, $cases['option_1'])) {
            return round($amount);
        }

        // https://docs.checkout.com/resources/calculating-the-value#Calculatingthevalue-Option2:Thevaluedividedby1000valuediv1000
        if (in_array($currency, $cases['option_2'])) {
            return round($amount * 1000);
        }

        // https://docs.checkout.com/resources/calculating-the-value#Calculatingthevalue-Option3:Thevaluedividedby100valuediv100
        return round($amount * 100);
    }

    private function processSuccessfulPayment(\Checkout\Models\Payments\Payment $_payment)
    {
        if ($this->checkout->payment_hash->data->store_card) {
            $this->storePaymentMethod($_payment);
        }

        $data = [
            'payment_method' => $_payment->source['id'],
            'payment_type' => PaymentType::parseCardType(strtolower($_payment->source['scheme'])),
            'amount' => $this->checkout->payment_hash->data->value,
        ];

        $payment = $this->checkout->createPayment($data, \App\Models\Payment::STATUS_COMPLETED);

        SystemLogger::dispatch(
            ['response' => $_payment, 'data' => $data],
            SystemLog::CATEGORY_GATEWAY_RESPONSE,
            SystemLog::EVENT_GATEWAY_SUCCESS,
            SystemLog::TYPE_CHECKOUT,
            $this->checkout->client
        );

        return redirect()->route('client.payments.show', ['payment' => $this->checkout->encodePrimaryKey($payment->id)]);
    }

    public function processUnsuccessfulPayment(\Checkout\Models\Payments\Payment $_payment)
    {
        PaymentFailureMailer::dispatch(
            $this->checkout->client,
            $_payment,
            $this->checkout->client->company,
            $this->checkout->payment_hash->data->value
        );

        $message = [
            'server_response' => $_payment,
            'data' => $this->checkout->payment_hash->data,
        ];

        SystemLogger::dispatch(
            $message,
            SystemLog::CATEGORY_GATEWAY_RESPONSE,
            SystemLog::EVENT_GATEWAY_FAILURE,
            SystemLog::TYPE_CHECKOUT,
            $this->checkout->client
        );

        throw new PaymentFailed($_payment->status, $_payment->http_code);
    }

    private function processPendingPayment(\Checkout\Models\Payments\Payment $_payment)
    {
        $data = [
            'payment_method' => $_payment->source['id'],
            'payment_type' => PaymentType::parseCardType(strtolower($_payment->source['scheme'])),
            'amount' => $this->checkout->payment_hash->data->value,
        ];

        $payment = $this->checkout->createPayment($data, \App\Models\Payment::STATUS_PENDING);

        SystemLogger::dispatch(
            ['response' => $_payment, 'data' => $data],
            SystemLog::CATEGORY_GATEWAY_RESPONSE,
            SystemLog::EVENT_GATEWAY_SUCCESS,
            SystemLog::TYPE_CHECKOUT,
            $this->checkout->client
        );

        try {
            return redirect($_payment->_links['redirect']['href']);
        } catch (\Exception $e) {
            return $this->processInternallyFailedPayment($e);
        }
    }

    private function processInternallyFailedPayment($e)
    {
        if ($e instanceof \Checkout\Library\Exceptions\CheckoutHttpException) {
            $error = $e->getBody();
        }

        if ($e instanceof \Exception) {
            $error = $e->getMessage();
        }

        PaymentFailureMailer::dispatch(
            $this->checkout->client,
            $error,
            $this->checkout->client->company,
            $this->checkout->payment_hash->data->value
        );

        SystemLogger::dispatch(
            $this->checkout->payment_hash,
            SystemLog::CATEGORY_GATEWAY_RESPONSE,
            SystemLog::EVENT_GATEWAY_ERROR,
            SystemLog::TYPE_CHECKOUT,
            $this->checkout->client,
        );

        throw new PaymentFailed($error, $e->getCode());
    }

    private function storePaymentMethod(\Checkout\Models\Payments\Payment $response)
    {
        try {
            $payment_meta = new \stdClass;
            $payment_meta->exp_month = (string) $response->source['expiry_month'];
            $payment_meta->exp_year = (string) $response->source['expiry_year'];
            $payment_meta->brand = (string) $response->source['scheme'];
            $payment_meta->last4 = (string) $response->source['last4'];
            $payment_meta->type = (int) GatewayType::CREDIT_CARD;

            $data = [
                'payment_meta' => $payment_meta,
                'token' => $response->id,
                'payment_method_id' => $this->checkout->payment_hash->data->payment_method_id,
            ];

            return $this->checokut->saveCard($data);
        } catch (\Exception $e) {
            session()->flash('message', ctrans('texts.payment_method_saving_failed'));
        }
    }
}
