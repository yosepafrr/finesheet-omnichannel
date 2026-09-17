# Finesheet Omnichannel - Future Roadmap

Dokumen ini berisi rencana pengembangan fitur-fitur besar (Epic) ke depannya agar tidak terlupa, berdasarkan diskusi terakhir.

---

## 🚀 Prioritas 1: Portal Operasional & Konveksi (Fulfillment Admin)
**Tujuan:** Memusatkan dan mempercepat proses pemenuhan pesanan (fulfillment) dari berbagai toko (Shopee & TikTok) dalam satu pintu, guna mencegah keterlambatan pengiriman dan meminimalisir pesanan batal otomatis.

**Detail Fitur yang Akan Dibangun:**
1. **Unified Order List (Daftar Pesanan Terpadu)**
   - Menampilkan seluruh pesanan dari SEMUA toko dalam satu tabel/tampilan.
   - Tidak dikelompokkan per toko, melainkan murni diurutkan berdasarkan waktu pesanan masuk (kronologis / *first in first out*).

2. **Sinkronisasi Stok via Spreadsheet**
   - Menghubungkan data produk pesanan dengan informasi stok *real-time* yang ada di Google Sheets (Spreadsheet) milik gudang/konveksi.

3. **Manajemen Status Internal Admin**
   - **Ketersediaan Produk**: Menandai apakah produk *ready* atau kosong.
   - **Logika Bundle/Paket**: Jika pesanan berupa paket (misal 3-in-1), sistem dapat mendeteksi dan memberi tanda apakah *semua* produk dalam paket tersebut *ready*, atau ada salah satu item yang kosong.
   - **Status Packing**: Menandai pesanan yang sudah dipacking vs belum.
   - **Status Cetak Resi**: Menandai pesanan yang resinya (AWB) sudah dicetak vs belum.

4. **Deadline & SLA Monitor (Anti-Batal)**
   - Mengambil data kapan batas akhir pengiriman pesanan (SLA / *Ship-by Date*) dari masing-masing *platform*.
   - Menampilkan indikator bahaya (*warning* merah) untuk pesanan yang besok atau hari ini akan batal otomatis jika tidak segera dikirim/di-request pickup.

5. **Fitur Cetak Resi Terpusat**
   - Admin konveksi dapat mencetak label pengiriman (AWB/Resi) secara massal langsung dari portal ini tanpa harus membuka *Seller Center* Shopee atau TikTok satu per satu.

6. **Device Tablet Friendly**
   - karena admin akan mengakses sistem ini menggunakan device tablet   

---

## 📈 Prioritas 2: Modul Keuangan & Analisis Iklan (Finance & Ads)
**Tujuan:** Mengukur efektivitas biaya iklan (Marketing) dan menghitung metrik keuntungan riil (Net Profit) setelah dikurangi seluruh biaya dan iklan.

**Detail Fitur yang Akan Dibangun:**
1. **Ads Spend Tracking (Pelacakan Biaya Iklan)**
   - Perekaman data biaya iklan yang dikeluarkan.
   - Filter pemantauan performa harian (Daily), mingguan (Weekly), dan bulanan (Monthly).

2. **Advanced Profit Dashboard**
   - Menampilkan laporan keuntungan bersih (Net Profit) harian, mingguan, bulanan.
   - Kalkulasi = (Pendapatan Kotor - Biaya Platform/Admin - Modal HPP - **Ads Spend**).

3. **Analisis Profitabilitas Kampanye (Anti-Boncos)**
   - Menghubungkan data pengeluaran suatu Iklan/Campaign dengan pesanan yang dihasilkan.
   - Menampilkan indikator apakah suatu iklan berstatus "Profit" (Untung) atau "Boncos" (Rugi), sehingga pengambil keputusan dapat mematikan iklan yang merugikan dengan cepat.

---
*Catatan: Dokumen ini dapat terus diperbarui (di-update) seiring dengan berkembangnya kebutuhan bisnis.*
