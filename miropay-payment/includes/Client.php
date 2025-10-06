<?php
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
      throw new ErrorException("couldn't complete the request");
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