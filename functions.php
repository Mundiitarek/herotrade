<?php
/**
 * ==========================================
 * TRADING PLATFORM - FUNCTIONS LIBRARY
 * All core trading functions and API integrations
 * Compatible with PHP 7.4+
 * ==========================================
 */

if (!defined('APP_ACCESS')) {
    die('Direct access not permitted');
}

// ==========================================
// PRICE & API FUNCTIONS
// ==========================================

/**
 * Get real-time price from TwelveData API or fallback to database
 */
function get_realtime_price($pdo, $symbol) {
    // Try to get from API
    $api_key = TWELVEDATA_API_KEY;
    
    if (!empty($api_key) && $api_key !== 'YOUR_TWELVEDATA_API_KEY_HERE') {
        // Check cache (30 seconds)
        $cache_key = "price_{$symbol}";
        if (isset($_SESSION[$cache_key]) && isset($_SESSION["{$cache_key}_time"]) && (time() - $_SESSION["{$cache_key}_time"] < 30)) {
            return $_SESSION[$cache_key];
        }
        
        // Remove USDT suffix for API
        $api_symbol = str_replace('USDT', '/USD', $symbol);
        
        $url = "https://api.twelvedata.com/price?symbol={$api_symbol}&apikey={$api_key}";
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($http_code === 200 && $response) {
            $data = json_decode($response, true);
            
            if (isset($data['price'])) {
                $price = (float)$data['price'];
                
                // Update cache
                $_SESSION[$cache_key] = $price;
                $_SESSION["{$cache_key}_time"] = time();
                
                // Update database
                try {
                    $stmt = $pdo->prepare("UPDATE trading_pairs SET current_price = ?, updated_at = NOW() WHERE symbol = ?");
                    $stmt->execute([$price, $symbol]);
                } catch (PDOException $e) {
                    error_log("Failed to update price in DB: " . $e->getMessage());
                }
                
                return $price;
            }
        }
    }
    
    // Fallback to database
    try {
        $stmt = $pdo->prepare("SELECT current_price FROM trading_pairs WHERE symbol = ?");
        $stmt->execute([$symbol]);
        $result = $stmt->fetch();
        
        if ($result && $result['current_price'] > 0) {
            return $result['current_price'];
        }
    } catch (PDOException $e) {
        error_log("Get price from DB error: " . $e->getMessage());
    }
    
    // Default fallback prices
    $default_prices = [
        'BTCUSDT' => 67234.50,
        'ETHUSDT' => 3456.78,
        'BNBUSDT' => 312.45,
        'XRPUSDT' => 0.52,
        'ADAUSDT' => 0.45,
        'SOLUSDT' => 98.76,
        'DOGEUSDT' => 0.08,
        'MATICUSDT' => 0.87
    ];
    
    return $default_prices[$symbol] ?? 100.00;
}

/**
 * Get market overview
 */
function get_market_overview($pdo) {
    try {
        $stmt = $pdo->query("
            SELECT * FROM trading_pairs
            WHERE is_active = 1
            ORDER BY volume_24h DESC
            LIMIT 10
        ");
        
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log("Get market overview error: " . $e->getMessage());
        return [];
    }
}

// ==========================================
// USER STATISTICS FUNCTIONS
// ==========================================

/**
 * Get user statistics
 */
function get_user_statistics($pdo, $user_id) {
    $stats = [
        'spot' => [
            'total' => 0,
            'closed' => 0,
            'open' => 0,
            'profit' => 0,
            'loss' => 0
        ],
        'binary' => [
            'total' => 0,
            'won' => 0,
            'lost' => 0,
            'profit' => 0,
            'loss' => 0
        ],
        'overall' => [
            'total_profit' => 0,
            'total_loss' => 0,
            'net_profit' => 0
        ]
    ];
    
    try {
        // Spot trades stats
        $stmt = $pdo->prepare("
            SELECT 
                COUNT(*) as total_trades,
                SUM(CASE WHEN status = 'closed' THEN 1 ELSE 0 END) as closed_trades,
                SUM(CASE WHEN status = 'open' THEN 1 ELSE 0 END) as open_trades,
                SUM(CASE WHEN profit_loss > 0 THEN profit_loss ELSE 0 END) as total_profit,
                SUM(CASE WHEN profit_loss < 0 THEN profit_loss ELSE 0 END) as total_loss
            FROM spot_trades
            WHERE user_id = ?
        ");
        $stmt->execute([$user_id]);
        $spot_stats = $stmt->fetch();
        
        if ($spot_stats) {
            $stats['spot']['total'] = (int)$spot_stats['total_trades'];
            $stats['spot']['closed'] = (int)$spot_stats['closed_trades'];
            $stats['spot']['open'] = (int)$spot_stats['open_trades'];
            $stats['spot']['profit'] = (float)$spot_stats['total_profit'];
            $stats['spot']['loss'] = (float)$spot_stats['total_loss'];
        }
        
        // Binary trades stats
        $stmt = $pdo->prepare("
            SELECT 
                COUNT(*) as total_trades,
                SUM(CASE WHEN status = 'won' THEN 1 ELSE 0 END) as won_trades,
                SUM(CASE WHEN status = 'lost' THEN 1 ELSE 0 END) as lost_trades,
                SUM(CASE WHEN profit_loss > 0 THEN profit_loss ELSE 0 END) as total_profit,
                SUM(CASE WHEN profit_loss < 0 THEN profit_loss ELSE 0 END) as total_loss
            FROM binary_trades
            WHERE user_id = ?
        ");
        $stmt->execute([$user_id]);
        $binary_stats = $stmt->fetch();
        
        if ($binary_stats) {
            $stats['binary']['total'] = (int)$binary_stats['total_trades'];
            $stats['binary']['won'] = (int)$binary_stats['won_trades'];
            $stats['binary']['lost'] = (int)$binary_stats['lost_trades'];
            $stats['binary']['profit'] = (float)$binary_stats['total_profit'];
            $stats['binary']['loss'] = (float)$binary_stats['total_loss'];
        }
        
        // Overall stats
        $stats['overall']['total_profit'] = $stats['spot']['profit'] + $stats['binary']['profit'];
        $stats['overall']['total_loss'] = $stats['spot']['loss'] + $stats['binary']['loss'];
        $stats['overall']['net_profit'] = $stats['overall']['total_profit'] + $stats['overall']['total_loss'];
        
    } catch (PDOException $e) {
        error_log("Get user statistics error: " . $e->getMessage());
    }
    
    return $stats;
}

/**
 * Get user's open spot trades
 */
function get_user_open_spot_trades($pdo, $user_id) {
    try {
        $stmt = $pdo->prepare("
            SELECT 
                st.*,
                tp.name_ar,
                tp.name_en,
                tp.icon,
                tp.current_price as market_price
            FROM spot_trades st
            JOIN trading_pairs tp ON st.pair_id = tp.id
            WHERE st.user_id = ? AND st.status = 'open'
            ORDER BY st.created_at DESC
        ");
        $stmt->execute([$user_id]);
        
        $trades = $stmt->fetchAll();
        
        // Calculate current P/L for each trade
        foreach ($trades as &$trade) {
            $current_price = $trade['market_price'];
            
            if ($trade['type'] === 'buy') {
                $trade['profit_loss'] = ($current_price - $trade['entry_price']) * $trade['quantity'];
            } else {
                $trade['profit_loss'] = ($trade['entry_price'] - $current_price) * $trade['quantity'];
            }
            
            $trade['profit_loss_percentage'] = ($trade['profit_loss'] / $trade['amount']) * 100;
        }
        
        return $trades;
        
    } catch (PDOException $e) {
        error_log("Get open trades error: " . $e->getMessage());
        return [];
    }
}

/**
 * Get user transactions
 */
function get_user_transactions($pdo, $user_id, $limit = 50, $offset = 0) {
    try {
        $stmt = $pdo->prepare("
            SELECT * FROM transactions
            WHERE user_id = ?
            ORDER BY created_at DESC
            LIMIT ? OFFSET ?
        ");
        $stmt->execute([$user_id, $limit, $offset]);
        
        return $stmt->fetchAll();
        
    } catch (PDOException $e) {
        error_log("Get transactions error: " . $e->getMessage());
        return [];
    }
}

/**
 * Get user notifications
 */
function get_user_notifications($pdo, $user_id, $unread_only = false, $limit = 20) {
    try {
        $sql = "SELECT * FROM notifications WHERE user_id = ?";
        $params = [$user_id];
        
        if ($unread_only) {
            $sql .= " AND is_read = 0";
        }
        
        $sql .= " ORDER BY created_at DESC LIMIT ?";
        $params[] = $limit;
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        
        return $stmt->fetchAll();
        
    } catch (PDOException $e) {
        error_log("Get notifications error: " . $e->getMessage());
        return [];
    }
}

// ==========================================
// SPOT TRADING FUNCTIONS
// ==========================================

/**
 * Open spot trade
 */
function open_spot_trade($pdo, $user_id, $pair_id, $symbol, $type, $amount, $leverage = 1.0) {
    try {
        // Validate
        if ($amount < MIN_TRADE_AMOUNT || $amount > MAX_TRADE_AMOUNT) {
            return ['success' => false, 'message' => 'المبلغ غير صحيح'];
        }
        
        // Get user
        $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ? AND is_active = 1");
        $stmt->execute([$user_id]);
        $user = $stmt->fetch();
        
        if (!$user || $user['balance'] < $amount) {
            return ['success' => false, 'message' => 'الرصيد غير كافٍ'];
        }
        
        // Get current price
        $entry_price = get_realtime_price($pdo, $symbol);
        if (!$entry_price) {
            return ['success' => false, 'message' => 'فشل الحصول على السعر'];
        }
        
        // Calculate
        $quantity = $amount / $entry_price;
        $fee = $amount * 0.001; // 0.1% fee
        $total_cost = $amount;
        
        // Start transaction
        $pdo->beginTransaction();
        
        // Deduct balance
        $stmt = $pdo->prepare("UPDATE users SET balance = balance - ? WHERE id = ?");
        $stmt->execute([$total_cost, $user_id]);
        
        // Create trade
        $stmt = $pdo->prepare("
            INSERT INTO spot_trades (
                user_id, pair_id, symbol, type, amount, entry_price, 
                current_price, quantity, total_cost, leverage, fee, status
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'open')
        ");
        
        $stmt->execute([
            $user_id, $pair_id, $symbol, $type, $amount, 
            $entry_price, $entry_price, $quantity, $total_cost, $leverage, $fee
        ]);
        
        $trade_id = $pdo->lastInsertId();
        
        // Log transaction
        $stmt = $pdo->prepare("
            INSERT INTO transactions (user_id, type, amount, status, reference_id)
            VALUES (?, 'trade_profit', ?, 'pending', ?)
        ");
        $stmt->execute([$user_id, -$total_cost, "SPOT_{$trade_id}"]);
        
        $pdo->commit();
        
        log_activity($pdo, 'spot_trade_open', "Opened {$type} trade for {$symbol}", $user_id);
        
        return [
            'success' => true,
            'message' => 'تم فتح الصفقة بنجاح',
            'trade_id' => $trade_id,
            'entry_price' => $entry_price
        ];
        
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log("Open spot trade error: " . $e->getMessage());
        return ['success' => false, 'message' => 'حدث خطأ أثناء فتح الصفقة'];
    }
}

/**
 * Close spot trade
 */
function close_spot_trade($pdo, $trade_id, $user_id) {
    try {
        // Get trade
        $stmt = $pdo->prepare("SELECT * FROM spot_trades WHERE id = ? AND user_id = ? AND status = 'open'");
        $stmt->execute([$trade_id, $user_id]);
        $trade = $stmt->fetch();
        
        if (!$trade) {
            return ['success' => false, 'message' => 'الصفقة غير موجودة'];
        }
        
        // Get current price
        $exit_price = get_realtime_price($pdo, $trade['symbol']);
        if (!$exit_price) {
            return ['success' => false, 'message' => 'فشل الحصول على السعر'];
        }
        
        // Calculate P/L
        if ($trade['type'] === 'buy') {
            $profit_loss = ($exit_price - $trade['entry_price']) * $trade['quantity'];
        } else {
            $profit_loss = ($trade['entry_price'] - $exit_price) * $trade['quantity'];
        }
        
        $profit_loss_percentage = ($profit_loss / $trade['amount']) * 100;
        $final_amount = $trade['amount'] + $profit_loss - $trade['fee'];
        
        // Start transaction
        $pdo->beginTransaction();
        
        // Update trade
        $stmt = $pdo->prepare("
            UPDATE spot_trades 
            SET exit_price = ?, profit_loss = ?, profit_loss_percentage = ?, 
                status = 'closed', closed_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$exit_price, $profit_loss, $profit_loss_percentage, $trade_id]);
        
        // Update balance
        $stmt = $pdo->prepare("UPDATE users SET balance = balance + ? WHERE id = ?");
        $stmt->execute([$final_amount, $user_id]);
        
        // Log transaction
        $transaction_type = $profit_loss >= 0 ? 'trade_profit' : 'trade_loss';
        $stmt = $pdo->prepare("
            INSERT INTO transactions (user_id, type, amount, status, reference_id)
            VALUES (?, ?, ?, 'completed', ?)
        ");
        $stmt->execute([$user_id, $transaction_type, $profit_loss, "SPOT_{$trade_id}"]);
        
        $pdo->commit();
        
        log_activity($pdo, 'spot_trade_close', "Closed trade with P/L: {$profit_loss}", $user_id);
        
        return [
            'success' => true,
            'message' => 'تم إغلاق الصفقة بنجاح',
            'profit_loss' => $profit_loss,
            'exit_price' => $exit_price
        ];
        
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log("Close spot trade error: " . $e->getMessage());
        return ['success' => false, 'message' => 'حدث خطأ'];
    }
}

// ==========================================
// BINARY OPTIONS FUNCTIONS
// ==========================================

/**
 * Open binary trade
 */
function open_binary_trade($pdo, $user_id, $pair_id, $symbol, $direction, $amount, $duration_seconds) {
    try {
        // Validate
        if ($amount < MIN_TRADE_AMOUNT) {
            return ['success' => false, 'message' => 'المبلغ أقل من الحد الأدنى'];
        }
        
        // Get user
        $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ? AND is_active = 1");
        $stmt->execute([$user_id]);
        $user = $stmt->fetch();
        
        if (!$user || $user['balance'] < $amount) {
            return ['success' => false, 'message' => 'الرصيد غير كافٍ'];
        }
        
        // Get price
        $entry_price = get_realtime_price($pdo, $symbol);
        if (!$entry_price) {
            return ['success' => false, 'message' => 'فشل الحصول على السعر'];
        }
        
        $payout_percentage = get_setting($pdo, 'binary_payout_percentage', 85);
        $expiry_time = date('Y-m-d H:i:s', time() + $duration_seconds);
        
        // Start transaction
        $pdo->beginTransaction();
        
        // Deduct balance
        $stmt = $pdo->prepare("UPDATE users SET balance = balance - ? WHERE id = ?");
        $stmt->execute([$amount, $user_id]);
        
        // Create trade
        $stmt = $pdo->prepare("
            INSERT INTO binary_trades (
                user_id, pair_id, symbol, direction, amount, entry_price,
                duration_seconds, expiry_time, payout_percentage, status
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')
        ");
        
        $stmt->execute([
            $user_id, $pair_id, $symbol, $direction, $amount,
            $entry_price, $duration_seconds, $expiry_time, $payout_percentage
        ]);
        
        $trade_id = $pdo->lastInsertId();
        
        $pdo->commit();
        
        log_activity($pdo, 'binary_trade_open', "Opened binary {$direction} for {$symbol}", $user_id);
        
        return [
            'success' => true,
            'message' => 'تم فتح الصفقة بنجاح',
            'trade_id' => $trade_id,
            'entry_price' => $entry_price,
            'expiry_time' => $expiry_time
        ];
        
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log("Open binary trade error: " . $e->getMessage());
        return ['success' => false, 'message' => 'حدث خطأ'];
    }
}

// ==========================================
// NOTIFICATION FUNCTIONS
// ==========================================

/**
 * Create notification
 */
function create_notification($pdo, $user_id, $type, $title_en, $title_ar, $message_en, $message_ar, $link = null) {
    try {
        $stmt = $pdo->prepare("
            INSERT INTO notifications (user_id, type, title_en, title_ar, message_en, message_ar, link)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        
        return $stmt->execute([$user_id, $type, $title_en, $title_ar, $message_en, $message_ar, $link]);
        
    } catch (PDOException $e) {
        error_log("Create notification error: " . $e->getMessage());
        return false;
    }
}

// ==========================================
// END OF FUNCTIONS
// ==========================================
?>
