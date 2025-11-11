<?php
// billing.php - Серверная часть для VK Play Billing
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    exit(0);
}

// Конфигурация
$GMRID = "45886"; // Ваш GMRID
$SECRET_KEY = "your_secret_key_here"; // Замените на ваш секретный ключ из кабинета разработчика

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
    if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
        return $_SERVER['HTTP_CLIENT_IP'];
    } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        return $_SERVER['HTTP_X_FORWARDED_FOR'];
    } else {
        return $_SERVER['REMOTE_ADDR'];
    }
}

// Основная логика
try {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input || !isset($input['product_id'])) {
        throw new Exception('Неверные данные запроса');
    }

    // Данные продуктов
    $products = [
        'coins_100' => [
            'amount' => 30.00,
            'currency' => 'RUB',
            'description' => '100 игровых монет'
        ],
        'coins_500' => [
            'amount' => 149.00,
            'currency' => 'RUB', 
            'description' => '500 игровых монет'
        ],
        'coins_1200' => [
            'amount' => 299.00,
            'currency' => 'RUB',
            'description' => '1200 игровых монет'
        ]
    ];

    $productId = $input['product_id'];
    
    if (!isset($products[$productId])) {
        throw new Exception('Продукт не найден');
    }

    $product = $products[$productId];
    
    // Формируем merchant_param
    $merchantParam = [
        'uid' => $input['user_id'] ?? 'default_user',
        'ip' => getUserIP(),
        'amount' => $product['amount'],
        'currency' => $product['currency'],
        'description' => $product['description'],
        'item_id' => $productId,
        'additional_param' => time() // timestamp для уникальности
    ];

    // Кодируем merchant_param
    $merchantParamJson = json_encode($merchantParam);
    
    // Формируем параметры для подписи
    $signatureParams = [
        'merchant_param' => $merchantParamJson
    ];
    
    // Создаем подпись
    $signature = generateSignature($signatureParams, $SECRET_KEY);
    
    // Формируем URL для VK Play Billing
    $billingUrl = "https://vkplay.ru/app/{$GMRID}/billing/client?sign={$signature}";
    
    // Отправляем запрос к VK Play Billing API
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $billingUrl);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, "merchant_param=" . urlencode($merchantParamJson));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) {
        throw new Exception('Ошибка соединения с VK Play: ' . $httpCode);
    }

    $billingResponse = json_decode($response, true);
    
    if (!$billingResponse || $billingResponse['status'] !== 'ok') {
        throw new Exception('Ошибка VK Play: ' . ($billingResponse['errmsg'] ?? 'Unknown error'));
    }

    // Возвращаем URL платежного окна
    echo json_encode([
        'status' => 'success',
        'payment_url' => $billingResponse['url'],
        'product_id' => $productId
    ]);

} catch (Exception $e) {
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage()
    ]);
}
?>
