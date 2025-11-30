<?php

require_once "config.php";

define('BITRIX_HOOK', $config['bitrixWebhook']);

/**
 * Функция для вызова API Битрикс24
 */
function callBitrixAPI($method, $params = []) {
    
    $url = BITRIX_HOOK . $method;
    $maxAttempts = 3;
    $attempt = 0;
    
    while ($attempt < $maxAttempts) {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($params),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_TIMEOUT => 60,
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        // Если нет сетевой ошибки - возвращаем результат
        if (!$error || strpos($error, 'Could not resolve host') === false) {
            if ($error) {
                return ['error' => 'curl_error', 'error_description' => $error];
            }
            
            $result = json_decode($response, true);
            
            if (!$result) {
                return ['error' => 'invalid_json', 'error_description' => 'Invalid JSON response'];
            }
            
            return $result;
        }
        
        $attempt++;
        if ($attempt < $maxAttempts) {
            sleep(1);
        }
    }
    
    return ['error' => 'network_error', 'error_description' => $error];
}

function getPaymentData($paymentId) {
    $result = callBitrixAPI('sale.payment.get', ['id' => $paymentId]);
    
    if (isset($result['error'])) {
        return [
            'success' => false,
            'error' => $result['error_description'] ?? 'Unknown API error',
            'payment_id' => $paymentId
        ];
    }
    
    if (!isset($result['result']['payment'])) {
        return [
            'success' => false,
            'error' => 'Payment not found in response',
            'payment_id' => $paymentId
        ];
    }
    
    $payment = $result['result']['payment'];
    
    return [
        'success' => true,
        'payment_id' => $paymentId,
        'data' => $payment
    ];
}

function processPayment($paymentId) {
    // Получаем дату 24 часа назад в формате Битрикс24
    $twentyFourHoursAgo = (new DateTime('-24 hours'))->format('Y-m-d\TH:i:sP');
    
    // Получаем оплату с фильтрами
    $paymentResult = callBitrixAPI('sale.payment.list', [
        'filter' => [
            'id' => $paymentId,
            'paid' => 'Y',                           // Только оплаченные
            '!datePaid' => false,                   // Дата оплаты не пустая
            '>=datePaid' => $twentyFourHoursAgo,    // Не старше 24 часов
        ],
        'select' => ['*']
    ]);
    
    if (isset($paymentResult['error'])) {
        return [
            'success' => false,
            'error' => $paymentResult['error_description'] ?? 'API error',
            'step' => 'get_payment'
        ];
    }
    
    // Проверяем структуру ответа для sale.payment.list
    if (!isset($paymentResult['result']['payments']) || empty($paymentResult['result']['payments'])) {
        return [
            'success' => false,
            'error' => 'Payment not found or does not meet criteria (paid in last 24 hours)',
            'payment_id' => $paymentId,
            'filter_date' => $twentyFourHoursAgo,
            'step' => 'get_payment'
        ];
    }
    
    // Получаем первую (и единственную) оплату из массива payments
    $payment = $paymentResult['result']['payments'][0];
    
    return [
        'success' => true,
        'payment_id' => $paymentId,
        'payment_data' => $payment,
        'date_paid' => $payment['datePaid'] ?? null
    ];
}


function getTransactionData($paymentData) {
    $orderId = $paymentData['orderId'] ?? null;
    
    if (!$orderId) {
        return [
            'success' => false,
            'error' => 'Order ID not found in payment data',
            'step' => 'get_order_entity'
        ];
    }
    
    // 1. Получаем связь заказа со сделкой
    $orderEntityResult = callBitrixAPI('crm.orderentity.list', [
        'filter' => ['orderId' => $orderId]
    ]);
    
    if (isset($orderEntityResult['error'])) {
        return [
            'success' => false,
            'error' => $orderEntityResult['error_description'] ?? 'Order entity API error',
            'step' => 'get_order_entity'
        ];
    }
    
    if (empty($orderEntityResult['result']['orderEntity'])) {
        return [
            'success' => false,
            'error' => 'Order entity not found',
            'step' => 'get_order_entity'
        ];
    }
    
    $orderEntity = $orderEntityResult['result']['orderEntity'][0];
    $dealId = $orderEntity['ownerId'] ?? null;
    
    if (!$dealId) {
        return [
            'success' => false,
            'error' => 'Deal ID not found in order entity',
            'step' => 'get_order_entity'
        ];
    }
    
    // 2. Получаем данные сделки
    $dealResult = callBitrixAPI('crm.deal.get', ['id' => $dealId]);
    
    if (isset($dealResult['error'])) {
        return [
            'success' => false,
            'error' => $dealResult['error_description'] ?? 'Deal API error',
            'step' => 'get_deal'
        ];
    }
    
    if (!isset($dealResult['result'])) {
        return [
            'success' => false,
            'error' => 'Deal data not found',
            'step' => 'get_deal'
        ];
    }
    
    $deal = $dealResult['result'];
    
    // 3. Получаем данные контакта для ФИО
    $contactId = $deal['CONTACT_ID'] ?? null;
    $contactName = 'Клиент';
    
    if ($contactId) {
        $contactResult = callBitrixAPI('crm.contact.get', ['id' => $contactId]);
        if (isset($contactResult['result'])) {
            $contact = $contactResult['result'];
            $lastName = $contact['LAST_NAME'] ?? '';
            $firstName = $contact['NAME'] ?? '';
            $secondName = $contact['SECOND_NAME'] ?? '';
            $contactName = trim("$lastName $firstName $secondName") ?: 'Клиент';
        }
    }
    
    return [
        'success' => true,
        'deal_id' => $dealId,
        'assigned_by_id' => $deal['ASSIGNED_BY_ID'] ?? 1,
        'contact_id' => $contactId,
        'contact_name' => $contactName,
        'deal_data' => $deal
    ];
}

function processClientBalance($transactionData, $paymentData) {
    $contactId = $transactionData['contact_id'] ?? null;
    
    if (!$contactId) {
        return [
            'success' => false,
            'error' => 'Contact ID not found for balance processing'
        ];
    }
    
    // Ищем существующий баланс клиента
    $balanceSearch = callBitrixAPI('crm.item.list', [
        'entityTypeId' => 1124,
        'filter' => ['contactId' => $contactId],
        'select' => ['id', 'title', 'opportunity']
    ]);
    
    $balanceId = null;
    $currentBalance = 0;
    $balanceTitle = '';
    
    if (isset($balanceSearch['result']['items']) && !empty($balanceSearch['result']['items'])) {
        // Баланс уже существует - получаем текущее значение
        $balanceItem = $balanceSearch['result']['items'][0];
        $balanceId = $balanceItem['id'];
        $currentBalance = floatval($balanceItem['opportunity'] ?? 0);
        $balanceTitle = $balanceItem['title'] ?? '';
    } else {
        // Если баланса нет, формируем название для нового
        $balanceTitle = $transactionData['contact_name'];
    }
    
    // Используем точные вычисления для денежных сумм
    $paymentAmount = floatval($paymentData['psSum'] ?? $paymentData['sum']);
    
    // Преобразуем в копейки для точного сложения
    $currentBalanceInCents = intval(round($currentBalance * 100));
    $paymentAmountInCents = intval(round($paymentAmount * 100));
    $newBalanceInCents = $currentBalanceInCents + $paymentAmountInCents;
    
    // Преобразуем обратно в рубли
    $newBalance = $newBalanceInCents / 100;
    
    // Форматируем до 2 знаков после запятой
    $newBalance = number_format($newBalance, 2, '.', '');
    
    // Формируем комментарий
    $accountNumber = $paymentData['accountNumber'] ?? 'Без номера';
    $dealTitle = $transactionData['deal_data']['TITLE'] ?? 'Без названия';
    $comment = "Поступление на баланс {$balanceTitle}, {$paymentAmount} рублей по счету {$accountNumber}, сделка {$dealTitle}";
    
    if ($balanceId) {
        // Обновляем существующий баланс
        $updateResult = callBitrixAPI('crm.item.update', [
            'entityTypeId' => 1124,
            'id' => $balanceId,
            'fields' => [
                'opportunity' => $newBalance
            ]
        ]);
        
        if (isset($updateResult['error'])) {
            return [
                'success' => false,
                'error' => $updateResult['error_description'] ?? 'Balance update error'
            ];
        }
        
        // Добавляем комментарий к существующему балансу
        $commentResult = callBitrixAPI('crm.timeline.comment.add', [
            'fields' => [
                'ENTITY_TYPE' => 'dynamic_1124',
                'ENTITY_ID' => $balanceId,
                'COMMENT' => $comment
            ]
        ]);
    } else {
        // Создаем новый баланс
        $createResult = callBitrixAPI('crm.item.add', [
            'entityTypeId' => 1124,
            'fields' => [
                'title' => $balanceTitle,
                'createdBy' => $transactionData['assigned_by_id'],
                'updatedBy' => $transactionData['assigned_by_id'],
                'movedBy' => $transactionData['assigned_by_id'],
                'assignedById' => $transactionData['assigned_by_id'],
                'opportunity' => $paymentAmount,
                'contactIds' => [$contactId]
            ]
        ]);
        
        if (isset($createResult['error'])) {
            return [
                'success' => false,
                'error' => $createResult['error_description'] ?? 'Balance creation error'
            ];
        }
        
        $balanceId = $createResult['result']['item']['id'] ?? null;
        
        // Добавляем комментарий к новому балансу
        if ($balanceId) {
            $commentResult = callBitrixAPI('crm.timeline.comment.add', [
                'fields' => [
                    'ENTITY_TYPE' => 'dynamic_1124',
                    'ENTITY_ID' => $balanceId,
                    'COMMENT' => $comment
                ]
            ]);
        }
    }
    
    return [
        'success' => true,
        'balance_id' => $balanceId,
        'old_balance' => $currentBalance,
        'new_balance' => $newBalance,
        'payment_amount' => $paymentAmount,
        'comment' => $comment
    ];
}

function checkTransactionExists($title) {
    $searchResult = callBitrixAPI('crm.item.list', [
        'entityTypeId' => 1128,
        'filter' => ['title' => $title],
        'select' => ['id', 'title']
    ]);
    
    if (isset($searchResult['error'])) {
        return [
            'success' => false,
            'error' => $searchResult['error_description'] ?? 'Transaction search error'
        ];
    }
    
    if (isset($searchResult['result']['items']) && !empty($searchResult['result']['items'])) {
        return [
            'success' => true,
            'exists' => true,
            'transaction_id' => $searchResult['result']['items'][0]['id'] ?? null
        ];
    }
    
    return [
        'success' => true,
        'exists' => false
    ];
}

function getPaymentProducts($paymentId) {
    // Получаем товары оплаты
    $productsResult = callBitrixAPI('crm.item.payment.product.list', [
        'paymentId' => $paymentId
    ]);
    
    if (isset($productsResult['error'])) {
        return [
            'success' => false,
            'error' => $productsResult['error_description'] ?? 'Cannot get payment products',
            'step' => 'get_payment_products'
        ];
    }
    
    if (!isset($productsResult['result']) || empty($productsResult['result'])) {
        return [
            'success' => true,
            'products' => [],
            'message' => 'No products found for this payment'
        ];
    }
    
    $paymentProducts = $productsResult['result'];
    $detailedProducts = [];
    
    // Для каждого товара получаем детальную информацию
    foreach ($paymentProducts as $product) {
        $rowId = $product['rowId'] ?? null;
        $quantity = $product['quantity'] ?? 0;
        
        if ($rowId) {
            $productDetail = getProductRowDetails($rowId);
            
            if ($productDetail['success']) {
                $detailedProducts[] = [
                    'payment_product_id' => $product['id'] ?? null,
                    'row_id' => $rowId,
                    'quantity' => $quantity,
                    'product_id' => $productDetail['product_id'] ?? null,
                    'product_name' => $productDetail['product_name'] ?? 'Неизвестный товар',
                    'price' => $productDetail['price'] ?? 0,
                    'total' => $quantity * ($productDetail['price'] ?? 0)
                ];
            } else {
                // Если не удалось получить детали, сохраняем базовую информацию
                $detailedProducts[] = [
                    'payment_product_id' => $product['id'] ?? null,
                    'row_id' => $rowId,
                    'quantity' => $quantity,
                    'product_name' => 'Товар (детали недоступны)',
                    'price' => 0,
                    'total' => 0,
                    'error' => $productDetail['error'] ?? 'Unknown error'
                ];
            }
        }
    }
    
    return [
        'success' => true,
        'payment_id' => $paymentId,
        'products' => $detailedProducts,
        'products_count' => count($detailedProducts),
        'total_amount' => array_sum(array_column($detailedProducts, 'total'))
    ];
}

function getProductRowDetails($rowId) {
    $productResult = callBitrixAPI('crm.item.productrow.get', [
        'id' => $rowId
    ]);
    
    if (isset($productResult['error']) || !isset($productResult['result']['productRow'])) {
        return [
            'success' => false,
            'error' => $productResult['error_description'] ?? 'Cannot get product details',
            'row_id' => $rowId
        ];
    }
    
    $productRow = $productResult['result']['productRow'];
    
    return [
        'success' => true,
        'row_id' => $rowId,
        'product_id' => $productRow['productId'] ?? null,
        'product_name' => $productRow['productName'] ?? 'Неизвестный товар',
        'price' => floatval($productRow['price'] ?? 0)
    ];
}

function addProductsToTransaction($transactionId, $productsData) {
    if (!$productsData['success'] || empty($productsData['products'])) {
        return [
            'success' => true,
            'message' => 'No products to add',
            'products_added' => 0
        ];
    }
    
    $addedProducts = [];
    $errors = [];
    
    foreach ($productsData['products'] as $product) {
        // Пропускаем товары с ошибками
        if (isset($product['error'])) {
            $errors[] = [
                'product_name' => $product['product_name'],
                'error' => $product['error']
            ];
            continue;
        }
        
        $productRowResult = callBitrixAPI('crm.item.productrow.add', [
            'fields' => [
                'ownerId' => $transactionId,
                'ownerType' => 'T468',
                'productId' => $product['product_id'],
                'productName' => $product['product_name'],
                'price' => $product['price'],
                'quantity' => $product['quantity'],
                'taxIncluded' => 'N',
                'measureCode' => 796
            ]
        ]);
        
        if (isset($productRowResult['error'])) {
            $errors[] = [
                'product_name' => $product['product_name'],
                'error' => $productRowResult['error_description'] ?? 'Unknown error'
            ];
        } else {
            $addedProducts[] = [
                'product_row_id' => $productRowResult['result']['item']['id'] ?? null,
                'product_name' => $product['product_name'],
                'quantity' => $product['quantity'],
                'price' => $product['price']
            ];
        }
    }
    
    $result = [
        'success' => empty($errors),
        'products_added' => count($addedProducts),
        'products_failed' => count($errors),
        'added_products' => $addedProducts
    ];
    
    if (!empty($errors)) {
        $result['errors'] = $errors;
    }
    
    return $result;
}

function createTransaction($paymentData, $transactionData, $balanceId, $productsInfo) {
    $accountNumber = $paymentData['accountNumber'] ?? 'Transaction ' . $paymentData['id'];
    
    $assignedById = $transactionData['assigned_by_id'] ?? 1;
    
    // 1. Создаем транзакцию
    $transactionFields = [
        'entityTypeId' => 1128,
        'fields' => [
            'title' => $accountNumber,
            'createdBy' => $assignedById,
            'updatedBy' => $assignedById,
            'movedBy' => $assignedById,
            'assignedById' => $assignedById,
            'lastActivityBy' => $assignedById,
            'opportunity' => $paymentData['psSum'] ?? $paymentData['sum'],
            'createdTime' => date('Y-m-d\TH:i:sP'),
            'categoryId' => 33,
            'parentId2' => $transactionData['deal_id'],
            'parentId1124' => $balanceId
        ]
    ];
    
    if ($transactionData['contact_id']) {
        $contactId = (int)$transactionData['contact_id'];
        $transactionFields['fields']['contactIds'] = [$contactId];
    }
    
    $transactionResult = callBitrixAPI('crm.item.add', $transactionFields);
    
    if (isset($transactionResult['error'])) {
        return [
            'success' => false,
            'error' => $transactionResult['error_description'] ?? 'Transaction creation error',
            'step' => 'create_transaction'
        ];
    }
    
    $transactionId = $transactionResult['result']['item']['id'] ?? null;
    
    if (!$transactionId) {
        return [
            'success' => false,
            'error' => 'Transaction ID not returned after creation',
            'step' => 'create_transaction'
        ];
    }
    
    // 2. Добавляем товары к транзакции
    $productsResult = addProductsToTransaction($transactionId, $productsInfo);
    
    return [
        'success' => true,
        'transaction_id' => $transactionId,
        'products_result' => $productsResult
    ];
}