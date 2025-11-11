<?php
// billing.php - Серверная часть для VK Play Billing
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    exit(0);
}

// Конфигурация - ВАШИ РЕАЛЬНЫЕ ДАННЫЕ
$GMRID = "45886"; // Ваш GMRID из кабинета разработчика
$SECRET_KEY = "gTxVtY76cuwlsugq"; // Ваш секретный ключ

// Функция для создания подписи
function generateSignature($params, $secretKey) {
    ksort($params);
    $string = '';
    foreach ($params as $key => $value) {
        $string .= $key . '=' . $value . '&';
    }
    $string = rtrim($string, '&');
    return md5($string . $secretKey);
}

// Функция для получения IP пользователя
function getUserIP() {
    $ip = '';
    
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
        $ip = trim($ips[0]);
    } elseif (!empty($_SERVER['HTTP_X_REAL_IP'])) {
        $ip = $_SERVER['HTTP_X_REAL_IP'];
    } elseif (!empty($_SERVER['REMOTE_ADDR'])) {
        $ip = $_SERVER['REMOTE_ADDR'];
    }
    
    // Если IP локальный, используем тестовый IP для разработки
    if ($ip === '127.0.0.1' || $ip === '::1' || empty($ip)) {
        $ip = '95.165.128.123'; // Тестовый IP России для RUB
    }
    
    return $ip;
}

// Основная логика
try {
    // Получаем входные данные
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input || !isset($input['product_id'])) {
        throw new Exception('Неверные данные запроса: product_id обязателен');
    }

    // Данные продуктов
    $products = [
        'coins_100' => [
            'amount' => 30.00,
            'currency' => 'RUB',
            'description' => '100 игровых монет',
            'coins' => 100
        ],
        'coins_500' => [
            'amount' => 149.00,
            'currency' => 'RUB', 
            'description' => '500 игровых монет',
            'coins' => 500
        ],
        'coins_1200' => [
            'amount' => 299.00,
            'currency' => 'RUB',
            'description' => '1200 игровых монет',
            'coins' => 1200
        ]
    ];

    $productId = $input['product_id'];
    
    if (!isset($products[$productId])) {
        throw new Exception('Продукт не найден: ' . $productId);
    }

    $product = $products[$productId];
    $userId = $input['user_id'] ?? 'user_' . uniqid();
    $userIP = getUserIP();
    
    // Логируем полученные данные
    error_log("VK Play Billing Request: product_id=$productId, user_id=$userId, ip=$userIP");

    // Формируем merchant_param согласно документации VK Play
    $merchantParam = [
        'uid' => $userId,
        'ip' => $userIP,
        'amount' => number_format($product['amount'], 2, '.', ''),
        'currency' => $product['currency'],
        'description' => $product['description'],
        'item_id' => $productId,
        'additional_param' => '2048_game_' . time()
    ];

    // Кодируем merchant_param
    $merchantParamJson = json_encode($merchantParam, JSON_UNESCAPED_UNICODE);
    
    // Формируем параметры для подписи
    $signatureParams = [
        'merchant_param' => $merchantParamJson
    ];
    
    // Создаем подпись
    $signature = generateSignature($signatureParams, $SECRET_KEY);
    
    // Формируем URL для VK Play Billing
    $billingUrl = "https://vkplay.ru/app/{$GMRID}/billing/client?sign={$signature}";
    
    error_log("VK Play Billing URL: $billingUrl");
    error_log("Merchant Param: $merchantParamJson");
    error_log("Signature: $signature");

    // Отправляем запрос к VK Play Billing API
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $billingUrl);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, "merchant_param=" . urlencode($merchantParamJson));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/x-www-form-urlencoded',
        'User-Agent: 2048-Game/1.0'
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    error_log("VK Play Response - HTTP Code: $httpCode");
    error_log("VK Play Response: $response");

    if ($curlError) {
        throw new Exception('Ошибка CURL: ' . $curlError);
    }

    if ($httpCode !== 200) {
        throw new Exception('Ошибка HTTP от VK Play: ' . $httpCode);
    }

    $billingResponse = json_decode($response, true);
    
    if (!$billingResponse) {
        throw new Exception('Неверный JSON ответ от VK Play');
    }
    
    if ($billingResponse['status'] !== 'ok') {
        $errorMsg = $billingResponse['errmsg'] ?? 'Unknown error';
        $errorCode = $billingResponse['errcode'] ?? 'Unknown code';
        throw new Exception("VK Play Error {$errorCode}: {$errorMsg}");
    }

    if (empty($billingResponse['url'])) {
        throw new Exception('VK Play не вернул payment URL');
    }

    // Возвращаем успешный ответ
    echo json_encode([
        'status' => 'success',
        'payment_url' => $billingResponse['url'],
        'product_id' => $productId,
        'product_data' => $product,
        'debug_info' => [
            'user_ip' => $userIP,
            'user_id' => $userId,
            'signature_used' => $signature,
            'gmrid' => $GMRID
        ]
    ]);

} catch (Exception $e) {
    error_log("VK Play Billing Error: " . $e->getMessage());
    
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage(),
        'debug_info' => [
            'server_time' => date('Y-m-d H:i:s'),
            'php_version' => PHP_VERSION,
            'secret_key_used' => substr($SECRET_KEY, 0, 4) . '...' // Логируем только первые 4 символа для безопасности
        ]
    ]);
}
?>
