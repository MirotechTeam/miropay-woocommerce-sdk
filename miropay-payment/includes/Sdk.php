<?php
require_once "./Client.php";


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

$sdk = new Sdk(
  "live",
  "live_laisbKnUBKDwZ9OaobuHzYxaEJ4Wh_rviAFpbndAYBmeh1Yqt0bwDXTW-lQPr1xZgFSgDOhuvuzteMQ9RqHneT1J",
  "-----BEGIN PRIVATE KEY-----\nMC4CAQAwBQYDK2VwBCIEIDXHd8YMq14ugjNuAibHATOu9m41g/4bf89kIF72AcKX\n-----END PRIVATE KEY-----",
  "http://host.docker.internal:3000/v1"
);
var_dump($sdk->get_status("a5df1384-1095-4820-9d23-afad2de6ea49"));
// $sdk->cancel("a5df1384-1095-4820-9d23-afad2de6ea49");

// var_dump($sdk->create_payment([
//   "amount" => "3000",
//   "title" => "title",
//   "description" => "description",
//   "redirect_url" => "https://google.com",
//   "gateways" => ["ZAIN"],
//   "collect_customer_email" => true,
//   "collect_customer_phone_number" => true,
//   "collect_fee_from_customer" => true
// ]));