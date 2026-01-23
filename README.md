# Laravel Marketplace client

Laravel Marketplace client adalah package Laravel untuk mengelola koneksi dan integrasi ke berbagai marketplace seperti **Shopee**, **Tokopedia**, dan marketplace lainnya dalam satu modul yang terstruktur dan mudah dikembangkan.

Package ini dirancang agar scalable, mudah dikonfigurasi, dan siap digunakan untuk kebutuhan sinkronisasi produk, pesanan dan pengiriman.

---

## ✨ Fitur

- 🔌 Koneksi multi-marketplace (Shopee, Tokopedia, dll)
- ⚙️ Konfigurasi terpusat melalui file config
- 🔐 Manajemen API Key, Secret, dan Token
- 📦 Struktur modular per marketplace
- 🔄 Mudah dikembangkan untuk marketplace baru
- 🧩 Mendukung Laravel Service Provider & Facade

---

## 📋 Requirement

- PHP >= 8.1
- Laravel >= 10.x
- Ekstensi PHP:
  - cURL
  - JSON
  - OpenSSL


---

## 📦 Instalasi

Install package melalui Composer:

```bash
composer require vendor/marketplace-client

````


---

## ⚙️ Service Provider

Pasangkan Service Provider pada file /bootstap/providers

```
return [
    App\Providers\AppServiceProvider::class,
    
    ...
    Virmata\MarketplaceClient\Provider::class,
    ...

];

```


## ⚙️ Publish Config & Migration

Package ini menyediakan **file konfigurasi** dan **database migration**.

### Publish Semua (Config + Migration)

```bash
php artisan vendor:publish --provider="Virmata\MarketplaceClient\Provider"
```

---

### Publish Hanya File Config

```bash
php artisan vendor:publish --tag=marketplace-config
```

File akan terpublish ke:

```text
config/marketplace.php
```

---


### Publish Hanya File Migration

```bash
php artisan vendor:publish --tag=marketplace-migrations
```

File migration akan muncul di:

```text
database/migrations/
```

Setelah itu jalankan:

```bash
php artisan migrate
```


---

## 🗄️ Struktur Database (Contoh)

Migration akan membuat tabel seperti:

### `marketplaces`

| Field        | Type      | Keterangan         |
| ------------ | --------- | ------------------ |
| id           | bigint    | Primary key        |
| via          | string    | shopee / tokopedia |
| code         | string    | API Client ID      |
| name         | text      | API Secret         |
| payload      | string    | ID toko            |
| created_at   | timestamp |                    |
| updated_at   | timestamp |                    |



---

## 🛠️ Konfigurasi

Contoh isi `config/marketplace.php`:

```php
return [
    "route_prefix" => 'marketplace',

    "model" => Virmata\MarketplaceClient\Models\Marketplace::class,

    "via" => [
        "shopee" => [
            "host" => "https://openplatform.sandbox.test-stable.shopee.sg",
            "authorize_url" => "https://open.sandbox.test-stable.shopee.com/auth",
        ],
        "tokopedia" => [
            "host" => "https://open-api.tiktokglobalshop.com",
            "auth" => "https://auth.tiktok-shops.com",
            "authorize_url" => "https://services.tiktokshop.com/open/authorize",
        ]
    ],
];
```

Tambahkan konfigurasi ke file `.env`:

```env

MARKETPLACE_SHOPEE_APPID= ______APP_ID______
MARKETPLACE_SHOPEE_SECRET=______SECRET______

MARKETPLACE_TOKOPEDIA_APPID=  ______APP_ID______
MARKETPLACE_TOKOPEDIA_SECRET= ______SECRET______
MARKETPLACE_TOKOPEDIA_SERVICE=______SERVICE______

```


---

## 🚀 Penggunaan Dasar

### Autentikasi Toko Marketplace

Load File marketplace-client.js

```
<head>
...

<script src="http://{domain-url}/js/markeplace-client.js"></script>

...
</head>
```

Lalu gunakan aksi fungsi untuk membuka popup register

```

window.MCJS.register('shopee').then((e) => {

    console.log('Logined', e)
    // { statement logic }

}).catch((e) => {
    console.warn('Login failed', e.message)
});

```

Setalah pupop terbuka, pengguna dapat melakukan login dan registrasi. Saat berhasil maka data informasi toko dan payload akan disimpan pada table "marketplaces". 

### Penggunaan Fitur

```
use Virmata\MarketplaceClient\Models\Marketplace;

$store = Marketplace::find("marketplace-store-id");
$store->getMarketplaceOrder();

```

### Menggunakan Dependency Injection

```php
use Vendor\Marketplace\Contracts\MarketplaceInterface;

public function index(MarketplaceInterface $marketplace)
{
    return $marketplace->getOrders();
}
```

### Menentukan Marketplace

```php
Marketplace::driver('tokopedia')->getOrders();
```

---

## 🧱 Struktur Package

```text
src/
├── Contracts/
│   └── MarketplaceInterface.php
├── Drivers/
│   ├── ShopeeDriver.php
│   └── TokopediaDriver.php
├── Services/
│   └── MarketplaceManager.php
├── Facades/
│   └── Marketplace.php
├── MarketplaceServiceProvider.php
config/
└── marketplace.php
```

---

## 🧪 Testing

Menjalankan unit test:

```bash
php artisan test
```

atau

```bash
vendor/bin/phpunit
```

---

## 🗺️ Roadmap

* [ ] Sinkronisasi Produk
* [ ] Sinkronisasi Pesanan
* [ ] Webhook Handler
* [ ] Scheduler & Queue Support
* [ ] Dashboard Monitoring
* [ ] Dokumentasi per Marketplace

---

## 🤝 Kontribusi

Kontribusi sangat terbuka. Silakan buat **issue** atau **pull request**.

1. Fork repository
2. Buat branch fitur (`feature/nama-fitur`)
3. Commit perubahan
4. Ajukan Pull Request

---

## 📄 Lisensi

Package ini dilisensikan di bawah lisensi **MIT**.

---

## 📞 Support

Jika ada pertanyaan atau bug, silakan hubungi melalui issue repository atau email developer.

---

**Happy Coding 🚀**

```

---

