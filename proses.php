<?php

/**
 * LOADER SAE - Data Puller dari API Dapodik ke SAE
 * 
 * Aplikasi ini berfungsi untuk menarik data dari API Dapodik (localhost:5774)
 * dan mengirimkannya ke aplikasi SAE melalui API endpoint
 */

// Load safety controls
require_once __DIR__ . '/safety.php';

try {
    // Inlined ConfigManager class (from config_manager.php)
    class ConfigManager
    {
        private $config_file;
        private $config_data;

        public function __construct($config_file = 'dynamic_config.json')
        {
            $this->config_file = __DIR__ . '/' . $config_file;
            $this->load_config();
        }

        private function load_config()
        {
            if (file_exists($this->config_file)) {
                $content = file_get_contents($this->config_file);
                $this->config_data = json_decode($content, true) ?: [];
            } else {
                $this->config_data = $this->get_default_config();
                $this->save_config();
            }
        }

        private function get_default_config()
        {
            return [
                'dapodik' => [
                    'base_url' => 'http://localhost:5774',
                    'token' => '',
                    'npsn' => '',
                    'last_token_update' => null,
                    'token_expires' => null
                ],
                'sae' => [
                    'base_url' => $this->detect_sae_url(),
                    'api_url' => '',
                    'api_key' => '',
                    'last_key_update' => null,
                    'is_hosting' => $this->is_hosting_environment()
                ],
                'sync' => [
                    'auto_sync' => false,
                    'interval' => 30,
                    'max_retries' => 3,
                    'timeout' => 30,
                    'last_successful_sync' => null
                ],
                'system' => [
                    'environment' => $this->detect_environment(),
                    'php_version' => PHP_VERSION,
                    'created_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s')
                ]
            ];
        }

        private function detect_sae_url()
        {
            if (file_exists(__DIR__ . '/hosting_handler.php')) {
                require_once __DIR__ . '/hosting_handler.php';
                if (class_exists('HostingEnvironment')) {
                    $class = 'HostingEnvironment';
                    $hosting = new $class();
                    if (method_exists($hosting, 'auto_detect_sae_url')) {
                        return $hosting->auto_detect_sae_url();
                    }
                }
            }

            $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
            $is_localhost = (strpos($host, 'localhost') !== false || strpos($host, '127.0.0.1') !== false);

            if ($is_localhost) {
                return 'http://localhost/saev5';
            } else {
                $protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https://' : 'http://';
                return rtrim($protocol . $host . '/saev5', '/');
            }
        }

        private function is_hosting_environment()
        {
            $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
            return !(strpos($host, 'localhost') !== false || strpos($host, '127.0.0.1') !== false);
        }

        private function detect_environment()
        {
            return $this->is_hosting_environment() ? 'hosting' : 'localhost';
        }

        private function save_config()
        {
            $this->config_data['system']['updated_at'] = date('Y-m-d H:i:s');
            file_put_contents($this->config_file, json_encode($this->config_data, JSON_PRETTY_PRINT));
        }

        public function get($key, $default = null)
        {
            $keys = explode('.', $key);
            $value = $this->config_data;

            foreach ($keys as $k) {
                if (isset($value[$k])) {
                    $value = $value[$k];
                } else {
                    return $default;
                }
            }

            return $value;
        }

        public function set($key, $value)
        {
            $keys = explode('.', $key);
            $config = &$this->config_data;

            foreach ($keys as $k) {
                if (!isset($config[$k]) || !is_array($config[$k])) {
                    $config[$k] = [];
                }
                $config = &$config[$k];
            }

            $config = $value;
            $this->save_config();
        }

        public function update_dapodik_token($token)
        {
            $this->set('dapodik.token', $token);
            $this->set('dapodik.last_token_update', date('Y-m-d H:i:s'));
            $this->set('dapodik.token_expires', date('Y-m-d H:i:s', strtotime('+24 hours')));
        }

        public function update_sae_api_key($api_key)
        {
            $this->set('sae.api_key', $api_key);
            $this->set('sae.last_key_update', date('Y-m-d H:i:s'));
        }

        public function update_sae_url($base_url)
        {
            $normalized_base_url = normalize_app_url($base_url);
            $normalized_api_url = build_sae_receive_data_url($normalized_base_url);

            if (preg_match('#/api/receive-data(?:\.php)?$#i', $normalized_base_url)) {
                $normalized_api_url = rtrim($normalized_base_url, '/');
                $normalized_base_url = preg_replace('#/api/receive-data(?:\.php)?$#i', '', $normalized_api_url);
            }

            $this->set('sae.base_url', $normalized_base_url);
            $this->set('sae.api_url', $normalized_api_url);
        }

        public function validate()
        {
            $errors = [];

            if (empty($this->get('dapodik.token'))) {
                $errors[] = 'Token Dapodik belum diisi';
            }

            if (empty($this->get('sae.api_key'))) {
                $errors[] = 'API Key SAE v4 belum diisi';
            }

            $token_expires = $this->get('dapodik.token_expires');
            if ($token_expires && strtotime($token_expires) < time()) {
                $errors[] = 'Token Dapodik mungkin sudah expired';
            }

            return $errors;
        }

        public function get_all()
        {
            return $this->config_data;
        }

        public function reset()
        {
            $this->config_data = $this->get_default_config();
            $this->save_config();
        }

        public function export_safe()
        {
            $safe_config = $this->config_data;
            if (isset($safe_config['dapodik']['token'])) {
                $safe_config['dapodik']['token'] = substr($safe_config['dapodik']['token'], 0, 8) . '***';
            }
            if (isset($safe_config['sae']['api_key'])) {
                $safe_config['sae']['api_key'] = substr($safe_config['sae']['api_key'], 0, 8) . '***';
            }

            return $safe_config;
        }
    }

    // Inlined Dapodik + SAE client functions (from lib/DapodikClient.php and lib/SaeClient.php)
    function normalize_app_url($url)
    {
        $url = trim((string) $url);
        if ($url === '') {
            return '';
        }

        return rtrim($url, '/');
    }

    function build_sae_receive_data_url($base_url)
    {
        $base_url = normalize_app_url($base_url);
        if ($base_url === '') {
            return '';
        }

        if (preg_match('#/api/receive-data(?:\.php)?$#i', $base_url)) {
            return preg_replace('#/api/receive-data(?:\.php)?$#i', '/api/receive-data', $base_url);
        }

        return $base_url . '/api/receive-data';
    }

    function get_sae_api_url($config, $override_base_url = null)
    {
        if ($override_base_url !== null && trim((string) $override_base_url) !== '') {
            return build_sae_receive_data_url($override_base_url);
        }

        $api_url = normalize_app_url($config->get('sae.api_url', ''));
        if ($api_url !== '') {
            return build_sae_receive_data_url($api_url);
        }

        return build_sae_receive_data_url($config->get('sae.base_url', ''));
    }

    function dapodik_get_raw($url, $token = null, $timeout = 30, $extra_opts = [])
    {
        $parsed = parse_url($url);
        if (!$parsed || !isset($parsed['host'])) {
            return ['response' => null, 'http_code' => 0, 'error' => 'Invalid URL'];
        }

        try {
            $config = new ConfigManager();
            $base = rtrim($config->get('dapodik.base_url', 'http://localhost:5774'), '/');
            $base_host = parse_url($base, PHP_URL_HOST);
            if (!empty($base_host) && strtolower($parsed['host']) !== strtolower($base_host)) {
                return ['response' => null, 'http_code' => 0, 'error' => 'URL host does not match configured Dapodik host'];
            }
        } catch (Exception $e) {
        }

        // Gunakan safeCurl dari LoaderSafety untuk rate limiting & retry logic
        $result = LoaderSafety::safeCurl($url, $token ?? '', $timeout, $max_retries = 2);
        
        if ($result['success']) {
            return ['response' => $result['data'], 'http_code' => $result['http_code'], 'error' => '', 'response_time_ms' => 0];
        } else {
            return ['response' => null, 'http_code' => $result['http_code'], 'error' => $result['error'], 'response_time_ms' => 0];
        }
    }

    function dapodik_json_decode($raw, $assoc = true)
    {
           $clean = trim((string) $raw);
           // Strip UTF-8 BOM (\xEF\xBB\xBF) — SAE hosting sometimes emits double BOM
           $clean = preg_replace('/^(\xEF\xBB\xBF)+/', '', $clean);
           $data = json_decode(fix_dapodik_json($clean), $assoc);
        if (json_last_error() === JSON_ERROR_NONE) return $data;
        // Fall back to original in case fix corrupted something
           return json_decode($clean, $assoc);
    }

        /**
         * ponytail: minimal JSON cleanup for Dapodik responses.
         * Handles single quotes, unquoted keys, trailing commas.
         * If Dapodik returns stricter JSON later, this becomes a passthrough.
         */
        function fix_dapodik_json($raw)
        {
            $s = trim((string) $raw);
            // Replace single-quoted strings with double-quoted (Dapodik uses single quotes)
            $s = preg_replace('/\'/', '"', $s);
            // Unquoted keys → quoted keys (e.g. { success: true } → { "success": true })
            $s = preg_replace('/([{,]\s*)([a-zA-Z_][a-zA-Z0-9_]*)\s*:/', '$1"$2":', $s);
            // Remove trailing commas before } or ]
            $s = preg_replace('/,\s*([}\]])/', '$1', $s);
            return $s;
        }

    function fetch_dapodik_data($endpoint)
    {
        global $dapodik_endpoints;
        if (!isset($dapodik_endpoints[$endpoint])) throw new Exception("Endpoint '$endpoint' tidak dikenal");
        $config = new ConfigManager();
        $endpoint_config = $dapodik_endpoints[$endpoint];
        $base_url = rtrim($config->get('dapodik.base_url', 'http://localhost:5774'), '/');
        $npsn = $config->get('dapodik.npsn', '');
        $token = $config->get('dapodik.token', '');

        $path = ltrim($endpoint_config['url'], '/');
        $url = $base_url . '/' . $path;

        // WebService Dapodik butuh token & npsn
        $params = [];
        if (!empty($token)) {
            $params['token'] = $token;
        }
        if (!empty($npsn)) {
            $params['npsn'] = $npsn;
        }

        if (!empty($params)) {
            $url .= (strpos($url, '?') === false ? '?' : '&') . http_build_query($params);
        }

        $resp = dapodik_get_raw($url, $token, $config->get('dapodik.timeout', 30));
        if (!empty($resp['error'])) {
            if (function_exists('log_message')) log_message('ERROR', "dapodik_get_raw error for $url: " . $resp['error']);
            throw new Exception('cURL Error: ' . $resp['error']);
        }
        if ($resp['http_code'] !== 200) {
            $raw = is_string($resp['response']) ? $resp['response'] : '';
            if (function_exists('log_message')) log_message('ERROR', "HTTP {$resp['http_code']} from Dapodik for $url - resp: " . sanitize_for_log($raw, 500));
            throw new Exception("HTTP Error: {$resp['http_code']} - Response: " . substr($raw, 0, 200));
        }

        $raw = is_string($resp['response']) ? $resp['response'] : '';
        $data = dapodik_json_decode($raw, true);
        $json_err = json_last_error();

        // Helper: try to fetch from a URL and parse JSON, return null on failure
        // ponytail: also fixes Dapodik single-quoted JSON before json_decode
        $try_fetch = function ($try_url, $try_token) use ($config) {
            $r = dapodik_get_raw($try_url, $try_token, $config->get('dapodik.timeout', 30));
            if (!empty($r['error']) || $r['http_code'] !== 200) return null;
            $parsed = dapodik_json_decode(is_string($r['response']) ? $r['response'] : '', true);
            return (json_last_error() === JSON_ERROR_NONE && is_array($parsed)) ? $parsed : null;
        };

        // Helper: collect 'rows' from parsed data, or single record, or null
        $extract_rows = function ($parsed) {
            if (isset($parsed['rows'])) {
                $rows = $parsed['rows'];
                if (is_array($rows)) {
                    return array_values($rows) !== $rows ? [$rows] : $rows;
                }
                return [$rows];
            }
            $topKeys = array_keys($parsed);
            $likely = ['npsn', 'nama', 'sekolah_id'];
            if (count(array_intersect($topKeys, $likely)) > 0) {
                return [$parsed];
            }
            return null;
        };

        // Case 1: Invalid JSON ÃƒÂ¢Ã¢â€šÂ¬Ã¢â‚¬Â try token renewal first, then legacy fallback
        if ($json_err !== JSON_ERROR_NONE) {
            $err = json_last_error_msg();
            $snippet = substr($raw, 0, 2000);
            if (function_exists('log_message')) log_message('ERROR', 'Invalid JSON from Dapodik: ' . $err . ' - snippet: ' . sanitize_for_log($snippet, 1000));

            // If session expired, log warning (do NOT attempt token renewal ÃƒÂ¢Ã¢â€šÂ¬Ã¢â‚¬Â loader is GET-only)
            // ponytail: token renewal requires POST to /login which violates GET-only constraint
            if (is_dapodik_session_expired($raw, null)) {
                if (function_exists('log_message')) log_message('WARNING', "Session expired for $endpoint. Token renewal disabled (GET-only loader). Manual token refresh required in Dapodik UI.");
            }

            if ($json_err !== JSON_ERROR_NONE) {
                $tried = [];
                $legacy_candidates = [];
                $ep_key = $endpoint;
                $base_no_slash = rtrim($base_url, '/');
                $legacy_candidates[] = $base_no_slash . '/WebService/' . $ep_key;
                $legacy_candidates[] = $base_no_slash . '/WebService/' . $ep_key . '?npsn=' . urlencode($npsn);
                if (!empty($token)) $legacy_candidates[] = $base_no_slash . '/WebService/' . $ep_key . '?token=' . urlencode($token);

                foreach ($legacy_candidates as $candidate) {
                    $tried[] = $candidate;
                    $r2 = dapodik_get_raw($candidate, $token, $config->get('dapodik.timeout', 30));
                    $raw2 = is_string($r2['response']) ? $r2['response'] : '';
                    $http2 = $r2['http_code'];
                    $err2 = $r2['error'];
                    if ($err2) {
                        if (function_exists('log_message')) log_message('WARNING', "Fallback attempt to $candidate failed: $err2");
                        continue;
                    }
                    if ($http2 !== 200) {
                        if (function_exists('log_message')) log_message('WARNING', "Fallback HTTP $http2 from $candidate - snippet: " . sanitize_for_log(substr($raw2, 0, 1000), 500));
                        continue;
                    }

                    $data2 = dapodik_json_decode($raw2, true);
                    if (json_last_error() === JSON_ERROR_NONE) {
                        if (function_exists('log_message')) log_message('INFO', "Fallback to legacy endpoint succeeded: $candidate");
                        $data = $data2;
                        $json_err = JSON_ERROR_NONE;
                        break;
                    } else {
                        if (function_exists('log_message')) log_message('WARNING', 'Fallback returned invalid JSON for ' . $candidate . ': ' . json_last_error_msg() . ' - snippet: ' . sanitize_for_log(substr($raw2, 0, 2000), 1000));
                    }
                }

                if ($json_err !== JSON_ERROR_NONE) {
                    if (function_exists('log_message')) log_message('ERROR', 'All attempts failed for ' . $url . '. Tried: ' . implode(', ', $tried));
                    throw new Exception('Invalid JSON: ' . $err);
                }
            }
        }

        // Case 2: REST API returned success:false (session expired) ÃƒÂ¢Ã¢â€šÂ¬Ã¢â‚¬Â try WebService with token param
        if (isset($data['success']) && $data['success'] === false) {
            $err_msg = $data['message'] ?? 'Unknown error';
            $session_expired = is_dapodik_session_expired($raw, $data);

            // If session expired, log warning (do NOT attempt token renewal ÃƒÂ¢Ã¢â€šÂ¬Ã¢â‚¬Â loader is GET-only)
            // ponytail: token renewal requires request to /login which violates GET-only constraint
            if ($session_expired) {
                if (function_exists('log_message')) log_message('WARNING', "Session expired for $endpoint. Token renewal disabled (GET-only loader). Manual token refresh required in Dapodik UI.");
            }

            if (isset($data['success']) && $data['success'] === false) {
                if (function_exists('log_message')) log_message('WARNING', "Dapodik REST returned error for $endpoint: $err_msg. Trying WebService with token...");

                $ep_key = $endpoint;
                $base_no_slash = rtrim($base_url, '/');
                $tried_ws = [];

                // Try WebService with token as URL param (no Bearer header ÃƒÂ¢Ã¢â€šÂ¬Ã¢â‚¬Â pass null token)
                if (!empty($token)) {
                    $ws_url = $base_no_slash . '/WebService/' . $ep_key . '?token=' . urlencode($token);
                    if (!empty($endpoint_config['use_npsn'])) {
                        $ws_url .= '&npsn=' . urlencode($npsn);
                    }
                    $tried_ws[] = $ws_url;
                    $ws_data = $try_fetch($ws_url, null); // no Bearer, token in URL
                    if ($ws_data !== null) {
                        $rows = $extract_rows($ws_data);
                        if ($rows !== null) {
                            if (function_exists('log_message')) log_message('INFO', "WebService fallback succeeded for $endpoint via token param: " . count($rows) . " records");
                            return $rows;
                        }
                    }
                }

                // Also try WebService without token (guest access)
                $ws_url = $base_no_slash . '/WebService/' . $ep_key;
                if (!empty($endpoint_config['use_npsn'])) {
                    $ws_url .= '?npsn=' . urlencode($npsn);
                }
                $tried_ws[] = $ws_url;
                $ws_data = $try_fetch($ws_url, $token);
                if ($ws_data !== null) {
                    $rows = $extract_rows($ws_data);
                    if ($rows !== null) {
                        if (function_exists('log_message')) log_message('INFO', "WebService fallback succeeded for $endpoint via Bearer: " . count($rows) . " records");
                        return $rows;
                    }
                }

                if (function_exists('log_message')) log_message('ERROR', "WebService fallback failed for $endpoint. Tried: " . implode(', ', $tried_ws));
                throw new Exception("Gagal mengambil data $endpoint dari Dapodik: $err_msg");
            }
        }

        // Normalize rows
        if (isset($data['rows'])) {
            $rows = $data['rows'];
            if (is_array($rows)) {
                $is_assoc = array_values($rows) !== $rows;
                if ($is_assoc) {
                    $results = [$rows];
                } else {
                    $results = $rows;
                }
            } else {
                $results = [$rows];
            }
        } else {
            // If response looks like single record (includes nama/npsn etc), wrap it
            $topKeys = is_array($data) ? array_keys($data) : [];
            $likely = ['npsn', 'nama', 'sekolah_id'];
            if (count(array_intersect($topKeys, $likely)) > 0) {
                $results = [$data];
            } else {
                if (function_exists('log_message')) log_message('ERROR', 'Unexpected Dapodik response structure for ' . $url . ': ' . sanitize_for_log($raw, 500));
                throw new Exception("Data 'rows' tidak ditemukan dalam response");
            }
        }

        if (!is_array($results)) throw new Exception('Invalid data structure in response');
        if (function_exists('log_message')) log_message('INFO', "Successfully fetched " . count($results) . " records from $endpoint");
        return $results;
    }

    function check_sae_connection($override_api_key = null, $override_base_url = null)
    {
        $config = new ConfigManager();
        $api_key = $override_api_key !== null ? $override_api_key : $config->get('sae.api_key');
        $api_url = get_sae_api_url($config, $override_base_url);

        if (empty($api_key)) {
            return ['status' => false, 'message' => 'API Key SAE belum diisi.', 'action_required' => 'update_api_key'];
        }

        $curl = curl_init();
        curl_setopt_array($curl, [
            CURLOPT_URL => $api_url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode(['endpoint' => 'auth_check', 'data' => []]),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $api_key,
                'User-Agent: Loader-SAE/1.0'
            ],
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
        ]);

        $response = curl_exec($curl);
        $http_code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $error = curl_error($curl);
        curl_close($curl);

        if ($error) return ['status' => false, 'message' => 'Koneksi gagal: ' . $error];
        if ($http_code === 200) {
            $data = dapodik_json_decode(is_string($response) ? $response : '', true);
            // Sukses jika API mengembalikan success=true, atau respons 200 valid (auth_check).
            if (isset($data['success']) && $data['success']) {
                return ['status' => true, 'message' => 'Koneksi berhasil'];
            }
            if (is_array($data) && ($data['status'] ?? '') === 'success') {
                return ['status' => true, 'message' => 'Koneksi berhasil'];
            }
            if (is_array($data) && ($data['authenticated'] ?? false)) {
                return ['status' => true, 'message' => 'Koneksi berhasil'];
            }
            // Respons 200 dengan body JSON valid dianggap terhubung (API key diterima).
            if (is_array($data)) {
                return ['status' => true, 'message' => $data['message'] ?? 'Koneksi berhasil'];
            }
        } elseif ($http_code === 404) {
            return ['status' => false, 'message' => 'API endpoint belum tersedia.', 'action_required' => 'check_api_file'];
        } elseif ($http_code === 401) {
            // Don't wipe api_key — could be transient. Let user retry or update manually.
            if (function_exists('log_message')) log_message('WARNING', 'SAE returned 401 on status check - api_key may be invalid');
            return ['status' => false, 'message' => 'API Key tidak valid atau SAE belum menerima key. Silakan verifikasi API Key di konfigurasi SAE.', 'action_required' => 'update_api_key'];
        }

        // All other non-200: parse response body for actual SAE error message
        $body_data = dapodik_json_decode(is_string($response) ? $response : '', true);
        if (is_array($body_data) && !empty($body_data['message'])) {
            return ['status' => false, 'message' => $body_data['message'] . ' (HTTP ' . $http_code . ')', 'action_required' => 'check_sae_config'];
        }
        $body_preview = trim(substr((string) $response, 0, 200));
        return ['status' => false, 'message' => 'HTTP Error: ' . $http_code . ($body_preview ? ' - ' . $body_preview : ''), 'action_required' => 'check_sae_config'];
    }

    function push_to_sae($endpoint, $data)
    {
        if (empty($data)) throw new Exception('Data kosong untuk endpoint: ' . $endpoint);

        $config = new ConfigManager();
        $sae_api_url = get_sae_api_url($config);

        $dapodik_base = rtrim($config->get('dapodik.base_url', 'http://localhost:5774'), '/');
        $dapodik_host = parse_url($dapodik_base, PHP_URL_HOST);
        $dapodik_port = parse_url($dapodik_base, PHP_URL_PORT);
        $dapodik_scheme = parse_url($dapodik_base, PHP_URL_SCHEME);
        if (empty($dapodik_port)) $dapodik_port = ($dapodik_scheme === 'https') ? 443 : 80;

        $sae_parsed_host = parse_url($sae_api_url, PHP_URL_HOST);
        $sae_parsed_port = parse_url($sae_api_url, PHP_URL_PORT);
        $sae_parsed_scheme = parse_url($sae_api_url, PHP_URL_SCHEME);
        if (empty($sae_parsed_port)) $sae_parsed_port = ($sae_parsed_scheme === 'https') ? 443 : 80;

        if (!empty($dapodik_host) && !empty($sae_parsed_host)) {
            $same_host = (strtolower($dapodik_host) === strtolower($sae_parsed_host));
            $same_port = (intval($dapodik_port) === intval($sae_parsed_port));
            if ($same_host && $same_port) {
                throw new Exception('Configured SAE API URL host dan port sama dengan Dapodik. Aborting push.');
            }
        }

        $payload = ['endpoint' => $endpoint, 'data' => $data, 'timestamp' => date('Y-m-d H:i:s'), 'total_records' => is_array($data) ? count($data) : 1];

        $curl = curl_init();
        curl_setopt_array($curl, [
            CURLOPT_URL => $sae_api_url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $config->get('sae.timeout', 30),
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $config->get('sae.api_key'), 'User-Agent: Loader-SAE/1.0'],
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
        ]);

        $response = curl_exec($curl);
        $http_code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $error = curl_error($curl);
        curl_close($curl);

        if ($error) throw new Exception('cURL Error saat push ke SAE: ' . $error);
        if ($http_code !== 200) {
            if ($http_code === 401) {
                if (function_exists('log_message')) log_message('WARNING', 'SAE API returned 401 Unauthorized - api_key may be invalid');
                throw new Exception('HTTP Error saat push ke SAE: 401 - API Key tidak valid. Silakan verifikasi API Key di konfigurasi SAE.');
            }
            throw new Exception('HTTP Error saat push ke SAE: ' . $http_code . ' - Response: ' . substr(is_string($response) ? $response : '', 0, 200));
        }

        $clean = trim(is_string($response) ? $response : '');
        $clean = preg_replace('/[\x00-\x1F\x80-\xFF]/', '', $clean);
        if (preg_match('/(\{.*\})/', $clean, $matches)) $clean = $matches[1];

        $result = json_decode($clean, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            if (function_exists('log_message')) log_message('INFO', 'Data terkirim ke SAE (response format non-standard)');
            return ['success' => true, 'message' => 'Data terkirim ke SAE (response format non-standard)'];
        }
        return $result;
    }

    // Inlined helper functions (from functions.php)
    if (!defined('MAX_RETRIES')) define('MAX_RETRIES', 3);
    if (!defined('SYNC_TIMEOUT')) define('SYNC_TIMEOUT', 30);
    if (!defined('CONNECT_TIMEOUT')) define('CONNECT_TIMEOUT', 10);

    // WebService endpoints used by local Dapodik Web Service API
    $dapodik_endpoints = [
        'getSekolah' => ['url' => 'WebService/getSekolah', 'use_npsn' => true, 'description' => 'Data Sekolah'],
        'getGtk' => ['url' => 'WebService/getGtk', 'use_npsn' => true, 'description' => 'Data GTK (Guru dan Tenaga Kependidikan)'],
        'getPesertaDidik' => ['url' => 'WebService/getPesertaDidik', 'use_npsn' => true, 'description' => 'Data Peserta Didik'],
        'getRombonganBelajar' => ['url' => 'WebService/getRombonganBelajar', 'use_npsn' => true, 'description' => 'Data Rombongan Belajar'],
        'getPengguna' => ['url' => 'WebService/getPengguna', 'use_npsn' => true, 'description' => 'Data Pengguna Sistem']
    ];

    function is_dapodik_session_expired($raw, $data = null)
    {
        if (is_string($raw) && stripos($raw, 'Session telah habis. harap melakukan login kembali') !== false) {
            return true;
        }
        if (is_array($data) && isset($data['message']) && stripos($data['message'], 'Session telah habis') !== false) {
            return true;
        }
        return false;
    }

    function renew_dapodik_token()
    {
        return false;
    }

    function check_dapodik_connection()
    {
        $config = new ConfigManager();
        $token = $config->get('dapodik.token');
        if (empty($token)) {
            return ['status' => false, 'message' => 'Token Dapodik belum diisi.', 'action_required' => 'update_token'];
        }

        try {
            $results = fetch_dapodik_data('getSekolah');
            if (!is_array($results) || empty($results)) return ['status' => false, 'message' => 'Response kosong atau tidak valid. Cek NPSN atau token.'];
            $sekolah = $results[0];
            $nama_sekolah = $sekolah['nama'] ?? 'N/A';
            $npsn = $sekolah['npsn'] ?? '';

            if (!empty($npsn)) {
                $config->set('dapodik.npsn', $npsn);
            }

            return [
                'status' => true,
                'message' => 'Koneksi berhasil',
                'sekolah' => $nama_sekolah,
                'npsn' => $npsn
            ];
        } catch (Exception $e) {
            $msg = $e->getMessage();
            return ['status' => false, 'message' => 'Gagal terhubung ke Dapodik: ' . $msg];
        }
    }

    function check_sae_connection_wrapper()
    {
        return check_sae_connection();
    }

    function sync_endpoint($endpoint, $dry_run = false)
    {
        $retries = 0;
        $max_retries = MAX_RETRIES;

        log_message('INFO', "Memulai kirim data endpoint: $endpoint");

        while ($retries < $max_retries) {
            try {
                log_message('INFO', "Mengambil data dari endpoint: $endpoint (attempt " . ($retries + 1) . ")");
                $data = fetch_dapodik_data($endpoint);

                $result = null;
                if (!$dry_run) {
                    $result = push_to_sae($endpoint, $data);
                } else {
                    log_message('INFO', "Dry run: skip push_to_sae for $endpoint - will not POST to SAE");
                }

                return [
                    'success' => true,
                    'message' => "Berhasil kirim data $endpoint",
                    'details' => count($data) . ' records berhasil dikirim',
                    'data_count' => count($data)
                ];
            } catch (Exception $e) {
                $retries++;
                $error_msg = "Attempt $retries failed for $endpoint: " . $e->getMessage();
                log_message('ERROR', $error_msg);

                if ($retries >= $max_retries) {
                    return [
                        'success' => false,
                        'message' => "Gagal kirim data $endpoint setelah $max_retries percobaan",
                        'details' => $e->getMessage()
                    ];
                }

                sleep(2);
            }
        }
    }

    function sync_all_endpoints($dry_run = false)
    {
        global $dapodik_endpoints;

        $all_data     = [];
        $fetch_errors = [];
        $total_records = 0;
        $total_endpoints = count($dapodik_endpoints);
        $ep_index = 0;
        $errors_list = [];

        clear_sync_progress();

        // Fetch all endpoints from Dapodik sequentially with progress
        foreach ($dapodik_endpoints as $endpoint => $ep_config) {
            $ep_index++;
            write_sync_progress([
                'stage' => 'fetching',
                'current_endpoint' => $endpoint,
                'total_endpoints' => $total_endpoints,
                'ep_index' => $ep_index,
                'endpoint_label' => $ep_config['description'],
                'rows_done' => 0,
                'rows_total' => 0,
                'message' => 'Mengambil ' . $ep_config['description'] . '...',
                'errors' => $errors_list
            ]);

            try {
                log_message('INFO', "Mengambil data dari Dapodik: $endpoint");
                $data = fetch_dapodik_data($endpoint);
                $all_data[$endpoint] = $data;
                $total_records += count($data);

                write_sync_progress([
                    'stage' => 'fetched',
                    'current_endpoint' => $endpoint,
                    'total_endpoints' => $total_endpoints,
                    'ep_index' => $ep_index,
                    'endpoint_label' => $ep_config['description'],
                    'rows_done' => count($data),
                    'rows_total' => count($data),
                    'message' => 'ÃƒÂ¢Ã…â€œÃ¢â‚¬Å“ ' . $ep_config['description'] . ': ' . count($data) . ' data',
                    'errors' => $errors_list
                ]);

                log_message('INFO', "Berhasil ambil " . count($data) . " records dari $endpoint");
            } catch (Exception $e) {
                $fetch_errors[$endpoint] = $e->getMessage();
                $errors_list[] = $endpoint . ': ' . $e->getMessage();
                log_message('ERROR', "Gagal ambil $endpoint: " . $e->getMessage());

                write_sync_progress([
                    'stage' => 'error',
                    'current_endpoint' => $endpoint,
                    'total_endpoints' => $total_endpoints,
                    'ep_index' => $ep_index,
                    'endpoint_label' => $ep_config['description'],
                    'rows_done' => 0,
                    'rows_total' => 0,
                    'message' => 'ÃƒÂ¢Ã…â€œÃ¢â‚¬â€ ' . $ep_config['description'] . ' gagal: ' . $e->getMessage(),
                    'errors' => $errors_list
                ]);
            }
        }

        if (empty($all_data)) {
            return [
                'success' => false,
                'message' => 'Semua endpoint gagal diambil dari Dapodik',
                'details' => implode('; ', array_map(function ($k, $v) { return "$k: $v"; }, array_keys($fetch_errors), $fetch_errors)),
                'results' => $fetch_errors
            ];
        }

        // Push all data to SAE in ONE request
        if (!$dry_run) {
            write_sync_progress([
                'stage' => 'pushing',
                'current_endpoint' => 'all',
                'total_endpoints' => $total_endpoints,
                'ep_index' => $total_endpoints,
                'endpoint_label' => 'Mengirim ke SAE',
                'rows_done' => 0,
                'rows_total' => $total_records,
                'message' => "Mengirim $total_records data ke SAE...",
                'errors' => $errors_list
            ]);

            try {
                $sae_result = push_all_to_sae($all_data);

                write_sync_progress([
                    'stage' => 'done',
                    'current_endpoint' => 'all',
                    'total_endpoints' => $total_endpoints,
                    'ep_index' => $total_endpoints,
                    'endpoint_label' => 'Selesai',
                    'rows_done' => $total_records,
                    'rows_total' => $total_records,
                    'message' => "ÃƒÂ¢Ã…â€œÃ¢â‚¬Å“ Berhasil: $total_records data terkirim ke SAE",
                    'errors' => $errors_list
                ]);
            } catch (Exception $e) {
                log_message('ERROR', "Gagal kirim data ke SAE: " . $e->getMessage());
                $errors_list[] = 'Kirim ke SAE: ' . $e->getMessage();
                write_sync_progress([
                    'stage' => 'error',
                    'current_endpoint' => 'all',
                    'total_endpoints' => $total_endpoints,
                    'ep_index' => $total_endpoints,
                    'endpoint_label' => 'Gagal kirim',
                    'rows_done' => 0,
                    'rows_total' => $total_records,
                    'message' => 'ÃƒÂ¢Ã…â€œÃ¢â‚¬â€ Gagal kirim ke SAE: ' . $e->getMessage(),
                    'errors' => $errors_list
                ]);
                return [
                    'success' => false,
                    'message' => 'Gagal kirim ke SAE: ' . $e->getMessage(),
                    'details' => $e->getMessage(),
                    'results' => []
                ];
            }
        } else {
            $sae_result = ['success' => true, 'message' => 'Dry run - data tidak dikirim ke SAE'];
            log_message('INFO', 'Dry run: skip push_all_to_sae');
        }

        $fetched  = count($all_data);
        $failed_fetch = count($fetch_errors);

        return [
            'success' => ($sae_result['success'] ?? false) && $failed_fetch === 0,
            'message' => "Kirim data selesai: $fetched/$total_endpoints endpoint berhasil diambil, $total_records records dikirim dalam 1 request",
            'details' => !empty($fetch_errors)
                ? 'Endpoint gagal diambil: ' . implode(', ', array_keys($fetch_errors))
                : "Total $total_records records berhasil dikirim ke SAE",
            'results' => $sae_result['details'] ?? $sae_result,
            'data_count' => $total_records
        ];
    }

    function push_all_to_sae($all_data)
    {
        if (empty($all_data)) throw new Exception('Data bundle kosong untuk dikirim ke SAE');

        $config = new ConfigManager();
        $sae_api_url = get_sae_api_url($config);

        // Security: ensure SAE URL host/port != Dapodik
        $dapodik_base   = rtrim($config->get('dapodik.base_url', 'http://localhost:5774'), '/');
        $dapodik_host   = parse_url($dapodik_base, PHP_URL_HOST);
        $dapodik_port   = parse_url($dapodik_base, PHP_URL_PORT);
        $dapodik_scheme = parse_url($dapodik_base, PHP_URL_SCHEME);
        if (empty($dapodik_port)) $dapodik_port = ($dapodik_scheme === 'https') ? 443 : 80;

        $sae_host   = parse_url($sae_api_url, PHP_URL_HOST);
        $sae_port   = parse_url($sae_api_url, PHP_URL_PORT);
        $sae_scheme = parse_url($sae_api_url, PHP_URL_SCHEME);
        if (empty($sae_port)) $sae_port = ($sae_scheme === 'https') ? 443 : 80;

        if (!empty($dapodik_host) && !empty($sae_host)) {
            if (strtolower($dapodik_host) === strtolower($sae_host) && intval($dapodik_port) === intval($sae_port)) {
                throw new Exception('SAE API URL host dan port sama dengan Dapodik. Aborting push.');
            }
        }

        $total_records = array_sum(array_map('count', $all_data));

        // Filter getPengguna: hanya kirim peran yang relevan ke SAE
        if (isset($all_data['getPengguna'])) {
            $allowed_peran = ['Kepala Sekolah', 'Operator Sekolah', 'PTK'];
            $all_data['getPengguna'] = array_values(array_filter(
                $all_data['getPengguna'],
                function ($p) use ($allowed_peran) {
                    return in_array($p['peran_id_str'] ?? '', $allowed_peran, true);
                }
            ));
            // Recalculate total after filter
            $total_records = array_sum(array_map('count', $all_data));
        }

        $payload = [
            'endpoint'      => 'syncAll',
            'data'          => $all_data,
            'timestamp'     => date('Y-m-d H:i:s'),
            'total_records' => $total_records
        ];

        $curl = curl_init();
        curl_setopt_array($curl, [
            CURLOPT_URL            => $sae_api_url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 120,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $config->get('sae.api_key'),
                'User-Agent: Loader-SAE/1.0'
            ],
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
        ]);

        $response  = curl_exec($curl);
        $http_code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $error     = curl_error($curl);
        curl_close($curl);
            if ($http_code === 401) {
                if (function_exists('log_message')) log_message('WARNING', 'SAE API returned 401 - api_key may be invalid');
                throw new Exception('HTTP 401 - API Key tidak valid. Silakan verifikasi API Key di konfigurasi SAE.');
            }

        if ($http_code !== 200) {
            throw new Exception('HTTP Error ' . $http_code . ': ' . substr(is_string($response) ? $response : '', 0, 200));
        }

        $clean = trim(is_string($response) ? $response : '');
        if (preg_match('/(\{.*\})/s', $clean, $matches)) $clean = $matches[1];
        $result = json_decode($clean, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return ['success' => true, 'message' => 'Data terkirim ke SAE'];
        }
        return $result;
    }

    function format_bytes($bytes, $precision = 2)
    {
        $units = ['B', 'KB', 'MB', 'GB'];

        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }

        return round($bytes, $precision) . ' ' . $units[$i];
    }

    function is_valid_json($string)
    {
        json_decode($string);
        return json_last_error() === JSON_ERROR_NONE;
    }

    function sanitize_for_log($data, $max_length = 100)
    {
        if (is_array($data) || is_object($data)) {
            $data = json_encode($data);
        }

        if (strlen($data) > $max_length) {
            $data = substr($data, 0, $max_length) . '...';
        }

        return $data;
    }

    function generate_request_id()
    {
        return uniqid('loader_', true);
    }

    function is_working_hours()
    {
        $hour = date('H');
        return ($hour >= 7 && $hour <= 16);
    }

    function log_message($level, $message)
    {
        $logs_dir = __DIR__ . '/logs';

        if (!is_dir($logs_dir)) {
            mkdir($logs_dir, 0755, true);
        }

        $timestamp = date('Y-m-d H:i:s');
        $log_entry = "[$timestamp] $level - $message" . PHP_EOL;

        $main_log = $logs_dir . '/sync.log';
        file_put_contents($main_log, $log_entry, FILE_APPEND | LOCK_EX);

        if ($level === 'ERROR') {
            $error_log = $logs_dir . '/error.log';
            file_put_contents($error_log, $log_entry, FILE_APPEND | LOCK_EX);
        }

        if (file_exists($main_log) && filesize($main_log) > 10 * 1024 * 1024) {
            rename($main_log, $main_log . '.' . date('Ymd_His'));
        }
    }

    // ---- Progress tracking for real-time UI ----
    $sync_progress_file = __DIR__ . '/sync_progress.json';

    function write_sync_progress($data)
    {
        global $sync_progress_file;
        file_put_contents($sync_progress_file, json_encode($data), LOCK_EX);
    }

    function get_sync_progress()
    {
        global $sync_progress_file;
        if (!file_exists($sync_progress_file)) return null;
        $d = json_decode(file_get_contents($sync_progress_file), true);
        return is_array($d) ? $d : null;
    }

    function clear_sync_progress()
    {
        global $sync_progress_file;
        @unlink($sync_progress_file);
    }

    // (Auto-sync removed in compact mode)

    $config = new ConfigManager();
} catch (Exception $e) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Error initializing application: ' . $e->getMessage()]);
    exit;
}

// Single-file API handler: kalau ada parameter action, tangani request dan keluar dengan JSON
if (isset($_REQUEST['action'])) {
    header('Content-Type: application/json');
    $action = $_REQUEST['action'];
    try {
        $config = new ConfigManager();
        switch ($action) {
            case 'status':
                $dapodik_status = check_dapodik_connection();
                $sae_status = check_sae_connection();
                echo json_encode(['success' => true, 'dapodik' => $dapodik_status, 'sae' => $sae_status, 'timestamp' => date('Y-m-d H:i:s')]);
                break;
            case 'sync':
                if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new Exception('Method tidak diizinkan');
                
                // Safety check 1: Rate limiting
                LoaderSafety::checkRateLimit($max_per_second = 2);
                
                // Safety check 2: Circuit breaker
                $breaker = LoaderSafety::checkCircuitBreaker();
                if ($breaker['status'] === 'open') {
                    throw new Exception($breaker['message']);
                }
                
                // Safety check 3: Token expiry
                if (LoaderSafety::isTokenExpired(__DIR__ . '/dynamic_config.json')) {
                    throw new Exception('Token Dapodik sudah expired. Silakan refresh di halaman config.');
                }
                
                $endpoint = $_POST['endpoint'] ?? '';
                $dry_run = false;
                if (empty($endpoint)) throw new Exception('Endpoint tidak boleh kosong');
                
                try {
                    if ($endpoint === 'all') {
                        $result = sync_all_endpoints($dry_run);
                    } else {
                        $result = sync_endpoint($endpoint, $dry_run);
                    }
                    echo json_encode($result);
                } catch (Exception $e) {
                    LoaderSafety::recordError();
                    throw $e;
                }
                break;
            case 'sync_progress':
                $prog = get_sync_progress();
                if ($prog) {
                    echo json_encode(['success' => true, 'progress' => $prog]);
                } else {
                    echo json_encode(['success' => false, 'message' => 'Tidak ada proses kirim data aktif']);
                }
                break;
            case 'test_current_config':
                $dapodik_status = check_dapodik_connection();
                $post_api_key = $_POST['api_key'] ?? null;
                $post_base_url = $_POST['base_url'] ?? null;
                $sae_status = check_sae_connection($post_api_key, $post_base_url);
                $validation = $config->validate();
                echo json_encode(['success' => ($dapodik_status['status'] && $sae_status['status']), 'dapodik' => $dapodik_status, 'sae' => $sae_status, 'validation_errors' => $validation]);
                break;
            case 'test_dapodik':
                $token = $_POST['token'] ?? '';
                $npsn = $_POST['npsn'] ?? '';
                if (empty($token)) throw new Exception('Token tidak boleh kosong');
                if (empty($npsn)) throw new Exception('NPSN tidak boleh kosong');
                $config->update_dapodik_token($token);
                $config->set('dapodik.npsn', $npsn);
                $result = check_dapodik_connection();
                echo json_encode(['success' => $result['status'], 'message' => $result['message'], 'status' => $result, 'sekolah' => $result['sekolah'] ?? '', 'npsn' => $result['npsn'] ?? '']);
                break;
            case 'test_sae':
                $api_key = $_POST['api_key'] ?? '';
                $base_url = $_POST['base_url'] ?? '';
                if (empty($api_key)) throw new Exception('API Key tidak boleh kosong');
                $config->update_sae_api_key($api_key);
                if (!empty($base_url)) $config->update_sae_url($base_url);
                $result = check_sae_connection($api_key, $base_url);
                echo json_encode(['success' => $result['status'], 'message' => $result['message'], 'status' => $result]);
                break;
            default:
                throw new Exception('Action tidak dikenal');
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// Default status untuk HTML render (jangan die bila Dapodik gagal)
try {
    $dapodik_status = check_dapodik_connection();
    $sae_status = check_sae_connection();
} catch (Exception $e) {
    $dapodik_status = ['status' => false, 'message' => $e->getMessage()];
    $sae_status = ['status' => false, 'message' => 'Cek API Key SAE'];
}
?>

