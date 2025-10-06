<?php

if (!defined('ABSPATH'))
  exit;


class MiroHttpClient
{

  private readonly string $xId;
  private readonly string $secret;
  private readonly string $secretKey64;
  private readonly string $baseUrl;
  private readonly string $contentType;

  public function __construct(string $baseUrl, string $contentType, string $decryptedPem, string $xId)
  {
    $this->baseUrl = $baseUrl;
    $this->contentType = $contentType;
    $this->xId = $xId;
    $this->secretKey64 = $this->generateSecretKey64($decryptedPem);
  }

  public function generateSecretKey64($decryptedPem)
  {
    $replacePem = preg_replace('/-----.* PRIVATE KEY-----|\s+/', '', $decryptedPem);
    $der = base64_decode($replacePem);
    $seed = substr($der, -32);
    $keyPair = sodium_crypto_sign_seed_keypair($seed);
    $secretKey64 = sodium_crypto_sign_secretkey($keyPair);

    return $secretKey64;
  }

  public function createSignature(string $method, string $path, string $secret)
  {
    $rawStr = "$method || $secret || $path";
    $signature = sodium_crypto_sign_detached($rawStr, $this->secretKey64);
    $encodedSignature = base64_encode($signature);
    return $encodedSignature;
  }

  public function get(string $path)
  {
    $signature = $this->createSignature("GET", "/v1/$path", $this->xId);
    $url = $this->generate_url($path);
    return $this->makeRequest($url, $signature, 'GET');
  }

  public function post(string $path, $data): MiroHttpResponse
  {
    $signature = $this->createSignature("POST", "/v1/$path", $this->xId);
    $url = $this->generate_url($path);
    return $this->makeRequest($url, $signature, 'POST', $data);
  }


  public function patch(string $path, $data = []): MiroHttpResponse
  {
    $signature = $this->createSignature("PATCH", "/v1/$path", $this->xId);
    $url = $this->generate_url($path);
    return $this->makeRequest($url, $signature, 'PATCH', $data);
  }



  private function makeRequest(string $url, string $signature, $method, $data = null)
  {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); // Return response as string
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true); // Follow redirects if any
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
      "x-id: $this->xId",
      "x-signature: $signature",
      "content-type: application/json"
    ]);

    if (in_array($method, ["POST", 'PATCH'])) {
      curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    }

    $result = curl_exec($ch);

    if (curl_errno($ch)) {
      throw new ErrorException("couldn't complete the request" . curl_errno($ch));
    }
    $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    curl_close($ch);

    $parsedContent = json_decode($result, true);
    $response = new MiroHttpResponse($statusCode, $parsedContent);
    return $response;
  }

  /* ================================ Privates ================================ */
  private function generate_url(string $path): string
  {
    return "$this->baseUrl/$path";
  }

  private function generate_response()
  {
    return [
      'status' => http_response_code()
    ];
  }

}


class MiroHttpResponse
{
  private $status;
  public $data;
  public function __construct($status, $data)
  {
    $this->status = $status;
    $this->data = $data;
  }


  public function get_status(): int
  {
    return $this->status;
  }
}

class MiroPaymentStatus
{
  public readonly string $reference_code;
  public readonly string $status;
  public readonly ?string $paid_via;
  public readonly ?string $paid_at;
  public readonly string $redirect_url;
  public readonly ?string $payout_amount;
  public function __construct($data)
  {
    $this->reference_code = $data["referenceCode"];
    $this->status = $data["status"];
    $this->paid_via = $data["paidVia"];
    $this->paid_at = $data["paidAt"];
    $this->redirect_url = $data["redirectUrl"];
    $this->payout_amount = $data["payoutAmount"];
  }
}
class Sdk
{
  private readonly MiroHttpClient $client;
  private readonly string $baseUrl;
  private readonly string $createPaymentUrl;
  private readonly string $cancelPaymentUrl;
  private readonly string $getPaymentStatusUrl;
  private readonly string $getPublicKeysUrl;
  private readonly string $secret;
  private readonly string $pvKey;

  public function __construct(string $mode, string $secret, string $pvKey, string $baseUrl)
  {
    $this->baseUrl = $baseUrl;
    $this->createPaymentUrl = "payment/rest/$mode/create";
    $this->getPaymentStatusUrl = "payment/rest/$mode/status";
    $this->cancelPaymentUrl = "payment/rest/$mode/cancel";
    $this->getPublicKeysUrl = "payment/rest/$mode/get-public-keys";
    $this->secret = $secret;
    $this->pvKey = $pvKey;


    $this->client = new MiroHttpClient($baseUrl, "application/json", $pvKey, $secret);
  }


  public function get_public_keys()
  {
    $response = $this->client->get($this->getPublicKeysUrl);
    var_dump($response);
  }


  public function create_payment($data)
  {
    if (
      empty($data["title"]) ||
      empty($data["amount"]) ||
      (empty($data["gateways"]) && $data["gateways"] !== []) ||
      empty($data["description"]) ||
      empty($data["redirect_url"]) ||
      empty($data["collect_customer_email"]) ||
      empty($data["collect_fee_from_customer"]) ||
      empty($data["collect_customer_phone_number"])
    ) {
      throw new Exception("missing required fields");
    }

    $response = $this->client->post($this->createPaymentUrl, [
      "amount" => $data["amount"],
      "title" => $data["title"],
      "description" => $data["description"],
      "redirectUrl" => $data["redirect_url"],
      "gateways" => $data["gateways"],
      "collectCustomerEmail" => $data["collect_customer_email"],
      "collectCustomerPhoneNumber" => $data["collect_customer_phone_number"],
      "collectFeeFromCustomer" => $data["collect_fee_from_customer"]
    ]);
    return new MiroPaymentStatus($response->data);
  }

  public function get_status($id)
  {
    $url = "$this->getPaymentStatusUrl/$id";
    $response = $this->client->get($url);
    return new MiroPaymentStatus($response->data);
  }

  public function cancel($id)
  {
    $url = "$this->cancelPaymentUrl/$id";
    $response = $this->client->patch($url);
    return new MiroPaymentStatus($response->data);
  }
}


class WC_Gateway_Miropaypayment extends WC_Payment_Gateway
{

  // public function __construct()
  // {
  //   $this->id = 'miropaypayment';
  //   $this->method_title = 'Miropay payment';
  //   $this->title = 'Miropay payment';
  //   $this->method_description = 'Pay securely using Your Payment System.';
  //   $this->has_fields = false; // no checkout fields yet

  //   $this->enabled = 'yes';

  //   // Load settings
  //   $this->init_form_fields();
  //   $this->init_settings();

  //   $this->supports = [
  //     'products',
  //     'subscriptions',
  //     'subscription_cancellation',
  //     'subscription_suspension',
  //     'subscription_reactivation',
  //     'subscription_amount_changes',
  //     'subscription_date_changes'
  //   ];

  //   // Save admin options
  //   add_action(
  //     'woocommerce_update_options_payment_gateways_' . $this->id,
  //     [$this, 'process_admin_options']
  //   );


  //   $this->title = $this->get_option('title');
  //   $this->description = $this->get_option('description');
  //   // $this->api_key = $this->get_option('api_key');
  //   // $this->has_fields = true;



  // }


  public function __construct()
  {
    $this->id = 'miropaypayment';
    $this->method_title = 'Miropay Payment';
    $this->method_description = 'Pay securely using Your Payment System.';
    $this->has_fields = false;

    // Load settings
    $this->init_form_fields();
    $this->init_settings();

    $this->enabled = $this->get_option('enabled');
    $this->title = $this->get_option('title', 'Miropay Payment'); // ✅ default
    $this->description = $this->get_option('description', 'Pay securely using Miropay.');

    add_action(
      'woocommerce_update_options_payment_gateways_' . $this->id,
      [$this, 'process_admin_options']
    );

  }

  public function init_form_fields()
  {
    $this->form_fields = [
      'enabled' => [
        'title' => 'Enable/Disable',
        'type' => 'checkbox',
        'label' => 'Enable Your Payment Gateway',
        'default' => 'yes',
      ],
      'secret' => [
        'title' => 'Secret',
        'type' => 'text',
        'description' => 'Enter your API key.',
        'default' => '',
      ],
      'api_key' => [
        'title' => 'API Key',
        'type' => 'text',
        'description' => 'Enter your API key.',
        'default' => '',
      ],
    ];
  }

  public function process_payment($order_id)
  {
    try {

      $sdk = new Sdk(
        "live",
        "live_laisj3SdxY7lWIY9NRSyBmTZzbHGO4HbDGO1q4OfZs-iYp9vkRCS7WNtwZvhY0x0F2xjiapzCvzcjmm-SmyKy9f-",
        "-----BEGIN PRIVATE KEY-----\nMC4CAQAwBQYDK2VwBCIEICl6O30TcmTy4IBx9GB3BNhMpYrO5YjzPGkg7T+CfKZG\n-----END PRIVATE KEY-----",
        "https://api.pallawan.com/v1"
      );

      $order = wc_get_order($order_id);



      wc_add_notice("Errrrrrrrrrrror1111111");
      wc_add_notice("Errr222222");
      // var_dump($order);
      // var_dump($sdk);

      $result = $sdk->create_payment([
        "title" => "Test payment",
        "description" => "Test description",
        "amount" => "2500",
        "gateways" => ["ZAIN", "FIB", "FAST_PAY"],
        "collect_customer_email" => true,
        "collect_customer_phone_number" => true,
        "collect_fee_from_customer" => true,
        "redirect_url" => "https://test.com"
      ]);

      wc_add_notice("Errrrrrrrrrrror2222222");
      // var_dump($order->get_checkout_order_received_url());


      return [
        'result' => 'success',
        'redirect' => "ttt",
      ];
      var_dump($result);

    } catch (Exception $e) {
      wc_add_notice($e);
    }
  }
  // 🔑 Force availability
  public function is_available()
  {
    error_log("calls available");
    return true;
  }

  // 🔑 Dummy payment processing (just marks order as paid for testing)
}
