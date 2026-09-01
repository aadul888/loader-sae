# Loader SAE - Safety Features

**Tujuan**: Memastikan Loader SAE TIDAK mengganggu sistem Dapodik yang kritis.

## 🛡️ Safety Controls Implemented

### 1. Rate Limiting (Max 2 Requests/Second)
- **Fungsi**: `LoaderSafety::checkRateLimit()`
- **Mekanisme**: Mencatat timestamp setiap request ke Dapodik, tunggu minimal 500ms antar request
- **Benefit**: Mencegah request bomb yang overload Dapodik WebService
- **Cache**: `/loader-sae/cache/ratelimit.txt`

### 2. Circuit Breaker (Auto Stop on Repeated Errors)
- **Fungsi**: `LoaderSafety::checkCircuitBreaker()` + `recordError()`
- **Mekanisme**: 
  - Jika 5+ error dalam 5 menit → "open circuit" selama 2 menit
  - Selama circuit open, semua sync request ditolak dengan status 500
  - Setelah 2 menit, circuit reset otomatis
- **Benefit**: Mencegah retry loop yang nyakol Dapodik
- **Status File**: `/loader-sae/cache/circuit_breaker.json`

### 3. Connection Management
- **Fresh Connections Only**: `CURLOPT_FRESH_CONNECT => true`
  - Setiap request membuat koneksi baru, tidak reuse pool
  - Jadi if crash terjadi, tidak "infect" connection pool
- **Explicit Close**: `Connection: close` header di setiap request
- **TCP Keepalive**: Idle 5 detik → close, avoid zombie connections

### 4. Timeout & Retry
- **Timeout**: 10 detik (connecttimeout 5s, total 10s)
- **Retry**: Max 2x retry dengan exponential backoff (1s, 2s)
  - HTTP 403/401 (auth error) → NO retry (langsung gagal)
  - Timeout/network error → retry
- **Benefit**: Jangan let request hang forever

### 5. Token Expiry Validation
- **Fungsi**: `LoaderSafety::isTokenExpired()`
- **Mekanisme**: Check `token_expires` di `dynamic_config.json` sebelum sync
- **Benefit**: Prevent auth loop jika token sudah expired

### 6. Payload Minimization
- **Fungsi**: `LoaderSafety::minifyPayload()`
- **Mekanisme**: Strip unnecessary fields dari Dapodik response
- **Allowed Fields**: `npsn, nama, alamat, kota, propinsi, kode_pos, telepon, website, email, kepala_sekolah, nip_kepala`
- **Benefit**: Mengurangi bandwidth ke SAE hosting, reduce parsing overhead

## 🔧 How to Use

### Konfigurasi Rate Limit
Edit di `proses.php` case 'sync':
```php
LoaderSafety::checkRateLimit($max_per_second = 2);  // Ubah ke nilai lain jika perlu
```

### Manual Circuit Breaker Reset
```php
@unlink(__DIR__ . '/cache/circuit_breaker.json');  // Hapus file ini untuk reset
```

### Test Safe Mode
Buka Loader UI → "Kirim Semua Data" button akan trigger:
1. Rate limit check (tunggu jika needed)
2. Circuit breaker check (error jika terbuka)
3. Token expiry check
4. Sync dengan retry logic

## 📊 Monitoring

### Check Rate Limit Status
File: `/loader-sae/cache/ratelimit.txt`
```json
[
    1693478400.5234,  // timestamp request terakhir
    1693478399.8234,
    1693478399.1234
]
```

### Check Circuit Breaker Status
File: `/loader-sae/cache/circuit_breaker.json`
```json
{
    "last_error_time": 1693478450,
    "error_count": 5
}
```
- Jika `error_count >= 5` dan `time() - last_error_time < 120`, circuit OPEN
- Setelah 120 detik, circuit akan close otomatis

### Logs
- Main: `/loader-sae/logs/loader.log`
- Sync: `/loader-sae/logs/sync.log`
- Cek error messages untuk identify masalah

## ⚠️ Known Limitations

1. **Static Cache Per Request**: Rate limit cache reset setiap PHP request, bukan persistent per-second.
   - Solusi: Gunakan Redis/Memcached jika perlu strict enforcement
   - Upgrade when: Concurrent requests > 10/second

2. **Manual Token Renewal**: Loader GET-only, tidak bisa POST untuk renew token.
   - Solusi: Monitor `token_expires` di UI, refresh manual di Dapodik jika perlu
   - Upgrade when: Need automatic token refresh

3. **No Database**: Semua state di file JSON, bukan DB.
   - Solusi: Read-only, tidak ada concurrency issue
   - Upgrade when: Need persistent audit trail

## 🚀 Future Improvements

- [ ] Redis-based rate limiting (untuk multi-process environment)
- [ ] Automatic token refresh via scheduled task
- [ ] Webhook notification kalo circuit breaker trigger
- [ ] Detailed metrics dashboard (requests/min, error rate, etc)
- [ ] Connection pooling dengan health check

## ✅ Checklist for Safe Deployment

- [x] Rate limiting enabled
- [x] Circuit breaker enabled  
- [x] Fresh connections only (no reuse)
- [x] Timeout set to 10s max
- [x] Retry dengan exponential backoff
- [x] Token expiry validation
- [x] Payload minimization
- [x] Error logging
- [ ] Production monitoring setup
- [ ] Dapodik admin notified of safety features

---

**Last Updated**: 2026-09-01
**Author**: Loader SAE Safety Team
