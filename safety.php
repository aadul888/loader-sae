<?php
/**
 * Loader SAE - Safety Controls
 * Rate limiting, throttling, dan proteksi dari Dapodik overload
 */

class LoaderSafety {
    
    /**
     * Rate limiter: max requests per second ke Dapodik
     */
    public static function checkRateLimit($max_per_second = 2) {
        $cache_key = 'loader_dapodik_ratelimit';
        $cache_file = __DIR__ . '/cache/ratelimit.txt';
        
        if (!file_exists(dirname($cache_file))) {
            mkdir(dirname($cache_file), 0755, true);
        }
        
        $now = microtime(true);
        $data = [];
        
        if (file_exists($cache_file)) {
            $content = @file_get_contents($cache_file);
            $data = $content ? json_decode($content, true) : [];
        }
        
        // Hapus requests yang lebih dari 1 detik yang lalu
        $data = array_filter($data, function($timestamp) use ($now) {
            return ($now - $timestamp) < 1;
        });
        
        if (count($data) >= $max_per_second) {
            $sleep_time = (int)(1000000 * (1 - ($now - reset($data))));
            if ($sleep_time > 0) {
                usleep($sleep_time);
            }
        }
        
        $data[] = $now;
        file_put_contents($cache_file, json_encode($data), LOCK_EX);
        
        return true;
    }
    
    /**
     * Circuit breaker: jika Dapodik error beruntun, stop requests sementara
     */
    public static function checkCircuitBreaker() {
        $cache_file = __DIR__ . '/cache/circuit_breaker.json';
        $config = [];
        
        if (file_exists($cache_file)) {
            $config = json_decode(file_get_contents($cache_file), true) ?: [];
        }
        
        $now = time();
        $last_error = $config['last_error_time'] ?? 0;
        $error_count = $config['error_count'] ?? 0;
        
        // Reset error count setiap 5 menit
        if ($now - $last_error > 300) {
            $error_count = 0;
        }
        
        // Jika 5+ error dalam 5 menit, buka circuit (stop requests untuk 2 menit)
        if ($error_count >= 5 && $now - $last_error < 120) {
            return [
                'status' => 'open',
                'message' => 'Circuit breaker opened. Dapodik error terlalu sering. Coba lagi dalam ' . (120 - ($now - $last_error)) . ' detik.',
                'retry_after' => $last_error + 120
            ];
        }
        
        return ['status' => 'closed'];
    }
    
    /**
     * Record error untuk circuit breaker
     */
    public static function recordError() {
        $cache_file = __DIR__ . '/cache/circuit_breaker.json';
        $config = [];
        
        if (file_exists($cache_file)) {
            $config = json_decode(file_get_contents($cache_file), true) ?: [];
        }
        
        $config['last_error_time'] = time();
        $config['error_count'] = ($config['error_count'] ?? 0) + 1;
        
        file_put_contents($cache_file, json_encode($config), LOCK_EX);
    }
    
    /**
     * Request timeout & retry logic untuk Dapodik
     */
    public static function safeCurl($url, $bearer_token, $timeout = 10, $max_retries = 2) {
        $retry_count = 0;
        $last_error = '';
        
        while ($retry_count <= $max_retries) {
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => $timeout,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_HTTPHEADER => [
                    'Authorization: Bearer ' . $bearer_token,
                    'User-Agent: LoaderSAE/1.0',
                    'Connection: close'  // Force close connection setiap request
                ],
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_TCP_KEEPALIVE => 1,
                CURLOPT_TCP_KEEPIDLE => 5,
                CURLOPT_FRESH_CONNECT => true  // Jangan reuse connection
            ]);
            
            $response = curl_exec($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curl_error = curl_error($ch);
            curl_close($ch);
            
            // Success
            if ($http_code === 200 && $response !== false) {
                return ['success' => true, 'data' => $response, 'http_code' => $http_code];
            }
            
            // HTTP 403/401 = auth error, jangan retry
            if (in_array($http_code, [401, 403])) {
                return [
                    'success' => false,
                    'error' => "Auth error (HTTP $http_code). Cek token Dapodik.",
                    'http_code' => $http_code
                ];
            }
            
            // Timeout atau connection error = retry
            $last_error = $curl_error ?: "HTTP $http_code";
            $retry_count++;
            
            if ($retry_count <= $max_retries) {
                // Exponential backoff: 1s, 2s, 4s
                $sleep_time = 1 << ($retry_count - 1);
                sleep($sleep_time);
            }
        }
        
        return [
            'success' => false,
            'error' => "Dapodik request failed after $max_retries retries: $last_error",
            'http_code' => 0
        ];
    }
    
    /**
     * Validate token expiry sebelum pakai
     */
    public static function isTokenExpired($config_file) {
        if (!file_exists($config_file)) {
            return true;
        }
        
        $config = json_decode(file_get_contents($config_file), true) ?: [];
        $token = $config['dapodik']['token'] ?? '';
        if (!$token) {
            return true;
        }

        $expires = $config['dapodik']['token_expires'] ?? '';
        
        // Token WebService Dapodik bersifat permanen kecuali diset expired secara eksplisit
        if (!$expires) {
            return false;
        }
        
        if (strtotime($expires) <= time()) {
            if (function_exists('check_dapodik_connection')) {
                $status = check_dapodik_connection();
                if (!empty($status['status'])) {
                    if (class_exists('ConfigManager')) {
                        $cm = new ConfigManager();
                        $cm->set('dapodik.token_expires', date('Y-m-d H:i:s', strtotime('+30 days')));
                    }
                    return false;
                }
            }
            return true;
        }

        return false;
    }
    
    /**
     * Minimal payload: hanya field yang diperlukan SAE
     */
    public static function minifyPayload($data) {
        $allowed_keys = [
            'npsn', 'nama', 'alamat', 'kota', 'propinsi', 'kode_pos',
            'telepon', 'website', 'email', 'kepala_sekolah', 'nip_kepala'
        ];
        
        $minified = [];
        foreach ($allowed_keys as $key) {
            if (isset($data[$key])) {
                $minified[$key] = $data[$key];
            }
        }
        
        return $minified;
    }
}
