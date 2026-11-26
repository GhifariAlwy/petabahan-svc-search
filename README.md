# PETABAHAN — Service Geosearch & Map Engine (`petabahan-svc-search`)

Microservice resmi **Fase 3** untuk pencarian geospasial bahan bangunan multi-material real-time, kalkulasi ranking *Worth It Score*, klasifikasi Pin Peta (Pin Hijau 100% vs Pin Kuning 1%–99%), dan proyeksi baca CQRS PostGIS.

---

## 1. Tanggung Jawab Service
1. **Pencarian Geospasial Spasial (`POST /api/v1/search`):**
   - Query radius PostGIS `ST_DWithin` (default 5 km, range 1 km – 25 km).
   - Filter kepatuhan status KYC (`is_approved`) dan penerimaan booking (`is_accepting_booking`).
   - Penegakan **Aturan DEC-005**: Filter saldo deposit supplier minimum (`is_deposit_sufficient = true`). Agen yang saldo depositnya di bawah batas ambang otomatis disembunyikan dari peta kontraktor.
2. **Klasifikasi Pin Peta (SRS FR-C3):**
   - **Pin Hijau (Green Pin):** 100% bahan belanja tersedia dan mencukupi stok.
   - **Pin Kuning (Yellow Pin):** 1% s.d. 99% bahan belanja tersedia.
   - **Pin 0%:** Otomatis dieliminasi dari respon.
3. **Kalkulasi & Ranking *Worth It Score* (SRS FR-C4):**
   - `Worth It Score = Subtotal Bahan + Estimasi Ongkir + Biaya Layanan Merchant`.
   - Ranking prioritas: Pin Hijau diurutkan teratas berdasarkan skor termurah (`worth_it_score ASC`), disusul Pin Kuning berdasarkan persentase kelengkapan tertinggi (`fulfillment_percentage DESC`, lalu `worth_it_score ASC`).
4. **Detail Transparan Toko (`GET /api/v1/search/{supplierId}/detail`):**
   - Menampilkan harga grosir berjenjang yang diterapkan per item dan rincian bahan yang tidak tersedia.
5. **Daftar Belanja (`shopping-lists`):**
   - Penyimpanan riwayat daftar belanja kontraktor untuk pencarian berkala.
6. **Proyeksi Baca CQRS (`search_supplier_projections`):**
   - Sinkronisasi asinkron dari `petabahan-svc-identity` dan `petabahan-svc-catalog`.

---

## 2. Struktur Direktori
```
app/
├── Domain/
│   ├── Auth/           # Forward-Auth Middleware & User Model
│   └── Search/
│       ├── Controllers/# SearchController, ShoppingListController, InternalSyncController
│       ├── Models/     # SearchSupplierProjection (CQRS), ShoppingList, ShoppingListItem
│       ├── Requests/   # SearchRequest
│       └── Services/   # SupplierSearchService, CqrsSyncService
├── Support/            # ApiResponse, ErrorCode, Geo helper
```

---

## 3. Pengujian Otomatis
Jalankan pengujian Pest untuk memverifikasi logika PostGIS, klasifikasi pin, dan aturan DEC-005:
```bash
./vendor/bin/pest
```
