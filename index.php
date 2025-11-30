<?php
require_once 'functions.php';

// Имя файла для логирования
$logFile = 'webhook.log';

// Получаем raw POST данные
$input = file_get_contents('php://input');

// Логируем сырые данные для отладки
file_put_contents('raw_input.log', date('Y-m-d H:i:s') . " - " . $input . "\n", FILE_APPEND);

// Парсим данные
parse_str($input, $data);

$timestamp = "[" . date('Y-m-d H:i:s') . "]";
$paymentId = $data['data']['FIELDS']['ID'] ?? null;

if ($paymentId) {
    // 1. Получаем данные об оплате
    $paymentResult = processPayment($paymentId);
    
    if (!$paymentResult['success']) {
        file_put_contents($logFile, $timestamp . " - " . json_encode($paymentResult) . "\n", FILE_APPEND);
        header('Content-Type: application/json');
        echo json_encode($paymentResult);
        exit;
    }
    
    // 2. Получаем дополнительные данные для транзакции
    $transactionDataResult = getTransactionData($paymentResult['payment_data']);
    
    if (!$transactionDataResult['success']) {
        file_put_contents($logFile, $timestamp . " - " . json_encode($transactionDataResult) . "\n", FILE_APPEND);
        header('Content-Type: application/json');
        echo json_encode($transactionDataResult);
        exit;
    }
    
    $accountNumber = $paymentResult['payment_data']['accountNumber'] ?? 'Transaction ' . $paymentId;
    
    // 3. Проверяем, существует ли уже транзакция
    $checkTransactionResult = checkTransactionExists($accountNumber);
    
    if (!$checkTransactionResult['success']) {
        file_put_contents($logFile, $timestamp . " - " . json_encode($checkTransactionResult) . "\n", FILE_APPEND);
        header('Content-Type: application/json');
        echo json_encode($checkTransactionResult);
        exit;
    }
    
    // 4. Если транзакция уже существует - пропускаем обновление баланса и создание транзакции
    if ($checkTransactionResult['exists']) {
        $finalResult = [
            'success' => true,
            'payment_id' => $paymentId,
            'order_id' => $paymentResult['payment_data']['orderId'] ?? null,
            'deal_id' => $transactionDataResult['deal_id'],
            'contact_id' => $transactionDataResult['contact_id'],
            'account_number' => $accountNumber,
            'amount' => $paymentResult['payment_data']['psSum'] ?? $paymentResult['payment_data']['sum'],
            'transaction_id' => $checkTransactionResult['transaction_id'],
            'message' => 'Transaction already exists, skipping balance update and transaction creation',
            'already_exists' => true
        ];
        
        file_put_contents($logFile, $timestamp . " - " . json_encode($finalResult) . "\n", FILE_APPEND);
        header('Content-Type: application/json');
        echo json_encode($finalResult);
        exit;
    }
    
        // 5. Обрабатываем баланс клиента (только если транзакции нет)
    $balanceResult = processClientBalance($transactionDataResult, $paymentResult['payment_data']);
    
    if (!$balanceResult['success']) {
        file_put_contents($logFile, $timestamp . " - " . json_encode($balanceResult) . "\n", FILE_APPEND);
        header('Content-Type: application/json');
        echo json_encode($balanceResult);
        exit;
    }
    
    // 6. Получаем информацию о товарах оплаты
    $productsInfo = getPaymentProducts($paymentId);
    
    // 7. Создаем транзакцию с товарами (только если транзакции нет)
    $transactionResult = createTransaction(
        $paymentResult['payment_data'], 
        $transactionDataResult, 
        $balanceResult['balance_id'],
        $productsInfo
    );
    
    // 8. Формируем финальный результат
    $finalResult = [
        'success' => $transactionResult['success'],
        'payment_id' => $paymentId,
        'order_id' => $paymentResult['payment_data']['orderId'] ?? null,
        'deal_id' => $transactionDataResult['deal_id'],
        'contact_id' => $transactionDataResult['contact_id'],
        'balance_id' => $balanceResult['balance_id'],
        'account_number' => $accountNumber,
        'amount' => $paymentResult['payment_data']['psSum'] ?? $paymentResult['payment_data']['sum'],
        'balance_old' => $balanceResult['old_balance'],
        'balance_new' => $balanceResult['new_balance']
    ];
    
    if ($transactionResult['success']) {
        $finalResult['transaction_id'] = $transactionResult['transaction_id'];
        $finalResult['already_exists'] = false;
        $finalResult['products_added'] = $transactionResult['products_result']['products_added'] ?? 0;
        $finalResult['products_failed'] = $transactionResult['products_result']['products_failed'] ?? 0;
    } else {
        $finalResult['error'] = $transactionResult['error'];
        $finalResult['step'] = $transactionResult['step'] ?? 'unknown';
    }
    
    // Логируем результат
    file_put_contents($logFile, $timestamp . " - " . json_encode($finalResult) . "\n", FILE_APPEND);
    
    // Отправляем ответ
    header('Content-Type: application/json');
    echo json_encode($finalResult);
} else {
    $errorResponse = [
        'success' => false,
        'error' => 'Payment ID not found in webhook data'
    ];
    
    file_put_contents($logFile, $timestamp . " - " . json_encode($errorResponse) . "\n", FILE_APPEND);
    
    header('Content-Type: application/json');
    echo json_encode($errorResponse);
}