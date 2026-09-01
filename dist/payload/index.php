<?php
/**
 * LOADER SAE - Frontend
 */

// Let the built-in PHP server serve real static assets directly; never bypass .php requests (breaks AJAX POSTs)
if (php_sapi_name() === 'cli-server') {
    $filePath = __DIR__ . parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    if (is_file($filePath) && strtolower(pathinfo($filePath, PATHINFO_EXTENSION)) !== 'php') {
        return false;
    }
}

// Load backend logic
require_once __DIR__ . '/proses.php';
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Loader SAE - Data Puller Dapodik</title>
    <link rel="icon" type="image/png" href="sae-logo.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/loader.css">
</head>

<body class="bg-light">
    <div class="container py-4 py-lg-5" style="max-width: 650px;">
        <div class="app-shell">
            <div class="surface-card card border-0">
                <div class="card-body">
                    <h2 class="section-title text-center mb-4">Loader SAE</h2>

                    <div class="row g-3 mb-4">
                        <div class="col-6 text-center">
                            <span id="dapodik-status-badge" class="mini-badge <?php echo $dapodik_status['status'] ? 'success' : 'error'; ?> w-100 justify-content-center">
                                <i class="fas fa-database me-2"></i>Dapodik: <?php echo $dapodik_status['status'] ? 'Terhubung' : 'Terputus'; ?>
                            </span>
                        </div>
                        <div class="col-6 text-center">
                            <span id="sae-status-badge" class="mini-badge <?php echo $sae_status['status'] ? 'success' : 'error'; ?> w-100 justify-content-center">
                                <i class="fas fa-cloud me-2"></i>SAE: <?php echo $sae_status['status'] ? 'Terhubung' : 'Terputus'; ?>
                            </span>
                        </div>
                    </div>

                    <div class="step-list">
                        <section class="step-card">
                            <div class="field-label">Token Dapodik</div>
                            <div class="input-group">
                                <input type="text" class="form-control" id="dapodik_token" 
                                    value="<?php echo htmlspecialchars($config->get('dapodik.token')); ?>" 
                                    placeholder="Masukkan token Dapodik"
                                    <?php echo $dapodik_status['status'] ? 'disabled' : ''; ?>>
                                <?php if (!$dapodik_status['status']): ?>
                                    <button class="btn btn-primary px-3" onclick="testDapodikConnection()">
                                        <i class="fas fa-save"></i>
                                    </button>
                                <?php endif; ?>
                            </div>
                        </section>

                        <section class="step-card">
                            <div class="field-label">Link Web SAE</div>
                            <div class="mb-3">
                                <input type="text" class="form-control" id="sae_base_url" 
                                    value="<?php echo htmlspecialchars($config->get('sae.base_url')); ?>" 
                                    placeholder="http://localhost/saev5"
                                    <?php echo $sae_status['status'] ? 'disabled' : ''; ?>>
                            </div>
                            <div class="field-label">Token API SAE</div>
                            <div class="input-group">
                                <input type="text" class="form-control" id="sae_api_key" 
                                    value="<?php echo htmlspecialchars($config->get('sae.api_key')); ?>" 
                                    placeholder="Masukkan token API SAE"
                                    <?php echo $sae_status['status'] ? 'disabled' : ''; ?>>
                                <?php if (!$sae_status['status']): ?>
                                    <button class="btn btn-primary px-3" onclick="testSaeConnection()">
                                        <i class="fas fa-save"></i>
                                    </button>
                                <?php endif; ?>
                            </div>
                        </section>

                        <section class="step-card sync-stage">
                            <div class="sync-cta">
                                <button class="btn btn-primary w-100" type="button" id="btn-sync" onclick="startSync()" 
                                    <?php echo (!($dapodik_status['status'] && $sae_status['status'])) ? 'disabled' : ''; ?>>
                                    <i class="fas fa-sync-alt me-2"></i>Kirim Semua Data
                                </button>

                                <div class="progress-shell progress-container mt-3">
                                    <div class="progress">
                                        <div class="progress-bar" role="progressbar" style="width: 0%"></div>
                                    </div>
                                    <div class="mt-2 small text-center text-muted">
                                        <span id="progress-text">Mempersiapkan...</span>
                                        <div id="progress-detail" class="small text-muted mt-1"></div>
                                    </div>
                                </div>
                            </div>
                        </section>
                    </div>

                    <div id="config-result" class="mt-3" style="display: none;"></div>

                    <div class="surface-card card border-0 mt-4">
                        <div class="card-header d-flex justify-content-between align-items-center py-2 bg-transparent">
                            <span class="fw-bold small"><i class="fas fa-terminal me-2"></i>Aktivitas</span>
                            <button type="button" class="btn btn-sm btn-link text-decoration-none p-0" onclick="clearLog()">Hapus</button>
                        </div>
                        <div class="card-body p-2">
                            <div class="log-container" id="log-container" style="max-height: 150px;">
                                <div class="text-muted">Menunggu...</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="assets/js/loader.js"></script>
</body>

</html>
