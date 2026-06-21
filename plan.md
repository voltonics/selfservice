# Cafe Management Panel - System Plan

## Overview

Cafe Management Panel adalah sistem berbasis web yang dirancang untuk mengelola seluruh operasional cafe dalam satu platform terintegrasi. Sistem menggunakan **Role-Based Access Control (RBAC)** sehingga setiap pengguna hanya dapat mengakses fitur sesuai tanggung jawabnya.

Tujuan utama sistem:

* Mempermudah operasional harian cafe.
* Meminimalkan human error.
* Menyediakan laporan bisnis secara real-time.
* Mendukung skalabilitas dari satu outlet hingga multi-outlet.
* Menjaga keamanan data melalui pembatasan akses berdasarkan role.

---

# System Workflow

## 1. Authentication

Setiap pengguna wajib login menggunakan akun yang telah terdaftar.

Setelah berhasil login, sistem akan:

* Memverifikasi identitas pengguna.
* Mengambil role dan permission.
* Mengarahkan pengguna ke dashboard yang sesuai.

---

## 2. Dashboard

Dashboard akan menyesuaikan berdasarkan role pengguna.

Contoh:

* Super Admin → statistik seluruh sistem.
* Owner → ringkasan bisnis dan laporan.
* Manager → operasional harian.
* Kasir → transaksi hari ini.
* Kitchen → daftar pesanan aktif.
* Inventory → stok dan bahan baku.

---

## 3. Order Flow

1. Customer membuat pesanan.
2. Sistem membuat order baru.
3. Kasir menerima atau membuat transaksi.
4. Kitchen menerima pesanan.
5. Kitchen mengubah status menjadi:

   * Pending
   * Preparing
   * Ready
   * Completed
6. Jika pembayaran berhasil, order dinyatakan selesai.
7. Data otomatis masuk ke laporan penjualan.

---

## 4. Inventory Flow

* Manager atau Inventory menambahkan stok masuk.
* Penggunaan bahan dapat dicatat sebagai stok keluar.
* Sistem menghitung stok tersisa.
* Jika stok melewati batas minimum, sistem dapat memberikan notifikasi.

---

## 5. Reporting

Semua transaksi tersimpan secara otomatis dan dapat menghasilkan laporan:

* Penjualan harian
* Penjualan bulanan
* Produk terlaris
* Pendapatan
* Pengeluaran
* Riwayat transaksi
* Aktivitas pengguna (audit log)

---

# Role Hierarchy

```
Super Admin
    │
    ▼
Owner
    │
    ▼
Manager
    ├──────────────┬───────────────┬───────────────┐
    ▼              ▼               ▼               ▼
Kasir         Kitchen         Inventory      (Opsional: Waiter)
```

Semakin tinggi posisi, semakin banyak permission yang dimiliki. Namun implementasi tetap menggunakan RBAC sehingga permission dapat diatur secara fleksibel tanpa bergantung sepenuhnya pada hierarki.

---

# Roles & Responsibilities

## 1. Super Admin

Role khusus developer atau administrator sistem.

Kemampuan:

* Full access ke seluruh fitur.
* Mengelola role dan permission.
* Mengelola seluruh outlet.
* Konfigurasi aplikasi.
* Backup dan restore database.
* Audit log.
* Pengaturan sistem.
* Maintenance.
* Impersonate user untuk troubleshooting (opsional).

---

## 2. Owner

Pemilik bisnis.

Kemampuan:

* Melihat seluruh laporan.
* Mengelola menu.
* Mengelola kategori.
* Mengelola promo dan voucher.
* Mengelola pegawai.
* Mengelola cabang (jika multi-outlet).
* Melihat dashboard bisnis.
* Mengakses data keuangan.
* Tidak memiliki akses teknis seperti backup database atau konfigurasi inti.

---

## 3. Manager

Penanggung jawab operasional harian.

Kemampuan:

* Seluruh kemampuan Kasir.
* Seluruh kemampuan Kitchen.
* Seluruh kemampuan Inventory.
* Mengelola menu.
* Mengelola kategori.
* Mengelola stok.
* Mengelola supplier.
* Melihat laporan operasional.
* Mengatur jadwal pegawai (opsional).

---

## 4. Kasir

Berfokus pada transaksi pelanggan.

Kemampuan:

* Membuat transaksi.
* Membuat order.
* Memproses pembayaran.
* Mengubah status pembayaran.
* Mencetak struk.
* Melihat riwayat transaksi.
* Membatalkan transaksi sesuai izin.
* Melihat status pesanan.

Tidak dapat:

* Menghapus laporan.
* Mengubah konfigurasi.
* Mengelola pegawai.

---

## 5. Kitchen / Barista

Berfokus pada proses produksi makanan dan minuman.

Kemampuan:

* Melihat pesanan masuk.
* Melihat catatan khusus pelanggan.
* Mengubah status order:

  * Pending
  * Preparing
  * Ready
  * Completed
* Melihat antrean produksi.

Tidak dapat:

* Mengakses laporan keuangan.
* Mengubah menu.
* Melakukan pembayaran.

---

## 6. Inventory

Berfokus pada manajemen bahan baku.

Kemampuan:

* Menambah stok masuk.
* Mengurangi stok keluar.
* Mengelola supplier.
* Melakukan stok opname.
* Melihat histori pergerakan stok.
* Melihat stok minimum.

Tidak dapat:

* Memproses transaksi pelanggan.
* Mengelola pegawai.
* Mengakses konfigurasi sistem.

---

# Permission Philosophy

Sistem menggunakan RBAC (Role-Based Access Control).

Contoh permission:

* dashboard.view
* menu.create
* menu.update
* menu.delete
* order.create
* order.update
* payment.process
* inventory.view
* inventory.update
* employee.manage
* report.view
* role.manage
* permission.manage
* settings.manage

Role hanyalah kumpulan permission sehingga akses dapat dikustomisasi sesuai kebutuhan bisnis.

---

# Suggested Future Features

* Multi-outlet management
* QRIS integration
* Loyalty points
* Membership system
* Online ordering
* Reservation system
* Kitchen display screen
* Digital receipt
* AI sales insights
* Automatic inventory deduction
* Purchase order management
* Supplier management
* Shift management
* Attendance system
* Expense tracking
* Refund management
* Customer feedback
* Audit log
* Notification center
* Export to Excel/PDF
* Dark mode

---

# Development Principles

* Mobile-friendly dan desktop-friendly.
* Semua aksi penting dicatat pada audit log.
* Soft delete untuk data penting.
* Validasi input pada sisi client dan server.
* Gunakan permission check pada setiap endpoint.
* Pisahkan authentication, authorization, dan business logic.
* Seluruh transaksi penting menggunakan database transaction untuk menjaga konsistensi data.
