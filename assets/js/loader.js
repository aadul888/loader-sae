let syncInProgress = false;
let syncInterval = null;
let progressPoll = null;

// Fungsi untuk menambah log
function addLog(message, type = "info") {
  const logContainer = document.getElementById("log-container");
  const timestamp = new Date().toLocaleString();
  const logClass =
    type === "error"
      ? "text-danger"
      : type === "success"
        ? "text-success"
        : "text-info";

  const logEntry = document.createElement("div");
  logEntry.innerHTML = `<span class="text-muted">[${timestamp}]</span> <span class="${logClass}">${message}</span>`;

  logContainer.appendChild(logEntry);
  logContainer.scrollTop = logContainer.scrollHeight;
}

// Fungsi untuk membersihkan log
function clearLog() {
  document.getElementById("log-container").innerHTML =
    '<div class="text-muted">Log dibersihkan...</div>';
}

// Poll progress from server
function pollProgress() {
  if (!syncInProgress) return;
  fetch("index.php?action=sync_progress")
    .then((r) => r.json())
    .then((data) => {
      if (!data.success || !data.progress) return;
      const p = data.progress;
      const progressContainer = document.querySelector(".progress-container");
      const progressBar = document.querySelector(".progress-bar");
      const progressText = document.getElementById("progress-text");
      const detailText = document.getElementById("progress-detail");

      progressContainer.style.display = "block";

      // Calculate percentage
      let pct = 0;
      if (p.stage === "fetching" || p.stage === "fetched") {
        // During fetch: ep_index / total_endpoints * 80%
        pct = Math.round((p.ep_index / p.total_endpoints) * 80);
        if (p.stage === "fetched")
          pct = Math.round((p.ep_index / p.total_endpoints) * 80);
      } else if (p.stage === "pushing") {
        pct = 85;
      } else if (p.stage === "done") {
        pct = 100;
      } else if (p.stage === "error") {
        pct = 100;
        progressBar.classList.add("bg-danger");
      }

      progressBar.style.width = pct + "%";
      progressText.textContent = p.message;
      if (detailText) {
        if (p.stage === "fetching" || p.stage === "fetched") {
          detailText.textContent =
            "Endpoint " +
            p.ep_index +
            " dari " +
            p.total_endpoints +
            " (" +
            p.rows_done +
            " data)";
        } else if (p.stage === "pushing") {
          detailText.textContent =
            "Mengirim " + p.rows_total + " data ke SAE...";
        } else if (p.stage === "done") {
          detailText.textContent = p.rows_done + " data berhasil dikirim";
        } else if (p.stage === "error") {
          detailText.textContent = "Terjadi kesalahan";
        }
      }

      if (p.stage === "done" || p.stage === "error") {
        setTimeout(() => {
          clearSyncInProgress();
        }, 2000);
      }
    })
    .catch(() => {});
}

function clearSyncInProgress() {
  syncInProgress = false;
  if (progressPoll) {
    clearInterval(progressPoll);
    progressPoll = null;
  }
  const btnSync = document.getElementById("btn-sync");
  btnSync.disabled = false;
  btnSync.innerHTML = '<i class="fas fa-sync-alt me-2"></i>Kirim Semua Data';
  setTimeout(() => {
    const pc = document.querySelector(".progress-container");
    if (pc) pc.style.display = "none";
    const pb = document.querySelector(".progress-bar");
    if (pb) {
      pb.style.width = "0%";
      pb.classList.remove("bg-danger");
    }
  }, 3000);
}

// Fungsi untuk memulai kirim data
function startSync() {
  if (syncInProgress) {
    addLog("Kirim Data sedang berlangsung!", "error");
    return;
  }

  const endpoint = "all";
  const btnSync = document.getElementById("btn-sync");
  const progressContainer = document.querySelector(".progress-container");
  const progressBar = document.querySelector(".progress-bar");
  const progressText = document.getElementById("progress-text");

  // Ensure progress-detail span exists
  let detailSpan = document.getElementById("progress-detail");
  if (!detailSpan) {
    detailSpan = document.createElement("div");
    detailSpan.id = "progress-detail";
    detailSpan.className = "small text-muted mt-1";
    document.querySelector(".progress-shell .mt-2").appendChild(detailSpan);
  }

  syncInProgress = true;
  btnSync.disabled = true;
  btnSync.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Mengirim...';
  progressContainer.style.display = "block";
  progressBar.style.width = "0%";
  progressBar.classList.remove("bg-danger");
  progressText.textContent = "Memulai proses Kirim Data...";

  addLog(`Memulai kirim data endpoint: ${endpoint}`, "info");

  // Start polling progress
  if (progressPoll) clearInterval(progressPoll);
  pollProgress();
  progressPoll = setInterval(pollProgress, 1500);

  // AJAX request untuk kirim data
  fetch("index.php?action=sync", {
    method: "POST",
    headers: {
      "Content-Type": "application/x-www-form-urlencoded",
    },
    body: `endpoint=${encodeURIComponent(endpoint)}`,
  })
    .then((response) => response.json())
    .then((data) => {
      progressBar.style.width = "100%";
      progressText.textContent = "Kirim Data selesai";

      if (data.success) {
        addLog(`Kirim Data berhasil: ${data.message}`, "success");
        if (data.details) {
          addLog(`Detail: ${data.details}`, "info");
        }
      } else {
        addLog(`Kirim Data gagal: ${data.message}`, "error");
        if (data.details) addLog(`Detail: ${data.details}`, "error");
      }
    })
    .catch((error) => {
      addLog(`Error: ${error.message}`, "error");
      progressBar.style.width = "100%";
      progressBar.classList.add("bg-danger");
      progressText.textContent = "Terjadi kesalahan";
    })
    .finally(() => {
      // Don't immediately clear — let polling update final state
      // Polling will call clearSyncInProgress after 2s
    });
}

// Initialize
document.addEventListener("DOMContentLoaded", function () {
  addLog("Loader SAE siap digunakan (compact mode)", "success");
});

// ===== FUNGSI KONFIGURASI =====

function testDapodikConnection() {
  const token = document.getElementById("dapodik_token").value.trim();
  const npsn = document.getElementById("dapodik_npsn")
    ? document.getElementById("dapodik_npsn").value.trim()
    : "";
  if (!token) {
    showConfigResult(false, "Token tidak boleh kosong");
    return;
  }
  if (!npsn) {
    showConfigResult(false, "NPSN tidak boleh kosong");
    return;
  }

  showConfigResult("loading", "Memverifikasi token & NPSN Dapodik...");

  const formData = new FormData();
  formData.append("action", "test_dapodik");
  formData.append("token", token);
  formData.append("npsn", npsn);

  fetch("index.php?action=test_dapodik", {
    method: "POST",
    body: formData,
  })
    .then((response) => response.json())
    .then((data) => {
      if (data.success) {
        const msg = data.sekolah
          ? "Koneksi Dapodik berhasil (" +
            data.sekolah +
            " - NPSN: " +
            (data.npsn || "N/A") +
            ")"
          : "Koneksi Dapodik berhasil";
        showConfigResult(true, msg);
        setTimeout(() => location.reload(), 2000);
      } else {
        showConfigResult(
          false,
          "Koneksi Dapodik gagal: " + (data.message || ""),
        );
      }
    })
    .catch((error) => {
      showConfigResult(false, "Error: " + error.message);
    });
}

function testSaeConnection() {
  const apiKey = document.getElementById("sae_api_key").value.trim();
  const baseUrl = document.getElementById("sae_base_url").value.trim();

  if (!apiKey) {
    showConfigResult(false, "API Key tidak boleh kosong");
    return;
  }

  showConfigResult("loading", "Memverifikasi koneksi SAE...");

  const formData = new FormData();
  formData.append("action", "test_sae");
  formData.append("api_key", apiKey);
  formData.append("base_url", baseUrl);

  fetch("index.php?action=test_sae", {
    method: "POST",
    body: formData,
  })
    .then((response) => response.json())
    .then((data) => {
      if (data.success) {
        showConfigResult(true, "Koneksi SAE berhasil");
        setTimeout(() => location.reload(), 2000);
      } else {
        showConfigResult(false, "Koneksi SAE gagal: " + (data.message || ""));
      }
    })
    .catch((error) => {
      showConfigResult(false, "Error: " + error.message);
    });
}

function testCurrentConfig() {
  showConfigResult("loading", "Mengtest konfigurasi saat ini...");

  const formData = new FormData();
  formData.append("action", "test_current_config");

  // Include current API Key and Base URL from inputs so test does not require saving
  const apiKeyInput = document.getElementById("sae_api_key");
  const baseUrlInput = document.getElementById("sae_base_url");
  if (apiKeyInput) formData.append("api_key", apiKeyInput.value.trim());
  if (baseUrlInput) formData.append("base_url", baseUrlInput.value.trim());

  fetch("index.php?action=test_current_config", {
    method: "POST",
    body: formData,
  })
    .then((response) => response.json())
    .then((data) => {
      let message = "Test Konfigurasi:<br>";
      message += `• Dapodik: ${data.dapodik?.status ? "✅" : "❌"} ${data.dapodik?.message || "Error"}<br>`;
      message += `• SAE v4: ${data.sae?.status ? "✅" : "❌"} ${data.sae?.message || "Error"}`;

      if (data.validation_errors && data.validation_errors.length > 0) {
        message += "<br>⚠️ " + data.validation_errors.join(", ");
      }

      showConfigResult(data.success, message);

      if (data.success) {
        setTimeout(() => location.reload(), 2000);
      }
    })
    .catch((error) => {
      showConfigResult(false, "Error: " + error.message);
    });
}

function showConfigResult(success, message) {
  const resultDiv = document.getElementById("config-result");

  if (success === "loading") {
    resultDiv.innerHTML = `
                    <div class="alert alert-info">
                        <i class="fas fa-spinner fa-spin me-2"></i>${message}
                    </div>
                `;
  } else {
    const alertClass = success ? "alert-success" : "alert-danger";
    const icon = success ? "check-circle" : "exclamation-triangle";

    resultDiv.innerHTML = `
                    <div class="alert ${alertClass}">
                        <i class="fas fa-${icon} me-2"></i>${message}
                    </div>
                `;
  }

  resultDiv.style.display = "block";
}

// Refresh status koneksi DISABLED - Manual only to prevent Dapodik overload
// Uncomment below if you really need auto-refresh, tapi maksimal 5 menit interval
/*
setInterval(() => {
  fetch("index.php?action=status")
    .then((response) => response.json())
    .then((data) => {
      // Update status UI jika diperlukan
      try {
        if (data && data.dapodik) {
          const dBadge = document.getElementById("dapodik-status-badge");
          const dEndpoint = document.getElementById("dapodik-endpoint");
          if (dBadge) {
            dBadge.className =
              "mini-badge " + (data.dapodik.status ? "success" : "error");
            dBadge.textContent = data.dapodik.status
              ? "Terhubung"
              : "Belum siap";
          }
          if (dEndpoint && data.dapodik.endpoint)
            dEndpoint.textContent = data.dapodik.endpoint;
        }
        if (data && data.sae) {
          const sBadge = document.getElementById("sae-status-badge");
          const sEndpoint = document.getElementById("sae-endpoint");
          if (sBadge) {
            sBadge.className =
              "mini-badge " + (data.sae.status ? "success" : "error");
            sBadge.textContent = data.sae.status ? "Terhubung" : "Belum siap";
          }
          if (sEndpoint && data.sae.endpoint)
            sEndpoint.textContent = data.sae.endpoint;

          // If SAE became disconnected, reload so server-side UI shows config form
          if (!data.sae.status) {
            console.warn(
              "SAE reported disconnected — reloading to show config form",
            );
            setTimeout(() => location.reload(), 800);
          }
        }
      } catch (e) {
        console.log("Status update error:", e);
      }
    })
    .catch((error) => console.log("Status check error:", error));
}, 30000);
*/

