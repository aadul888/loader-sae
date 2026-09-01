# Loader SAE - Manual Mode Only

## 🚨 PERUBAHAN PENTING (2026-09-01)

### Masalah
Loader UI melakukan **auto-refresh status koneksi setiap 30 detik**, yang menyebabkan:
- Permintaan ke Dapodik terus-menerus
- Overload pada Dapodik WebService
- Error dan timeout di Dapodik

### Solusi
**DISABLE auto-refresh** → Loader sekarang **MANUAL MODE ONLY**.

- User harus klik tombol/refresh page secara manual untuk check status atau sync
- Tidak ada polling background lagi
- Dapodik tidak akan di-ganggu

### File yang Diubah
- `assets/js/loader.js` — Commented out `setInterval()` untuk status check (line 333-377)

### Mode Operasi Baru

#### Status Check (Manual)
1. Buka http://loader-sae.test/
2. Klik **refresh page** (F5) untuk update status koneksi Dapodik/SAE
3. Status akan shown once

#### Kirim Data (Manual)
1. Klik tombol **"Kirim Semua Data"** (hanya 1x klik)
2. System akan:
   - Check rate limiting
   - Check circuit breaker
   - Fetch dari Dapodik (dengan retry)
   - Push ke SAE (dengan retry)
   - Show progress
3. Tunggu sampai selesai

### Fitur Keselamatan Tetap Aktif
✅ Rate limiting (2 req/sec)
✅ Circuit breaker (5+ errors)
✅ Timeout & retry
✅ Token validation
✅ Fresh connections only

### Upgrade Path
Jika perlu auto-refresh nanti:
- Edit `assets/js/loader.js` line 333
- Uncomment `setInterval()` block
- **TAPI**: Gunakan interval minimal **5 menit (300000 ms)** dan cache hasil

```javascript
// Contoh dengan 5 menit + caching
setInterval(() => {
  if (Date.now() - lastStatusCheck < 300000) return; // Skip jika < 5 min
  fetch("index.php?action=status")...
}, 300000);
```

---

**Status**: ✅ IMPLEMENTED
**Date**: 2026-09-01
**Tested**: Manual refresh works
