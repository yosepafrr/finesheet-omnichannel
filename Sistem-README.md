### Yang harus di run di terminal (pengembangan)

### LARAVEL REACT

- npm run dev
- php artisan schedule:work
<!-- - php artisan job -->
- php artisan queue:work --queue=orders
- php artisan queue:work --queue=products
- php artisan reverb:start
- php artisan serve

### Akun
yosep.adrianaa@gmail.com
yosep123

yosepadrianafauziramdani@gmail.com
yosep32134

Password postgresql
nitro5
agustin1417

NEXT GAWE:
1. apakah webhook sudah diterapkan di tiktokshop? ya, tapi idk why it looks like doesnt work or just delayed [done]
2. escrow mengambil dari mana? [done]
3. variant product tambah image [done] 
4. sinkronisasi produk maupun order lebih baik di pisahkan setiap toko nya [done]
5. edit harga hpp tambahkan edit batch (massal) seperti mekanisme edit massal pada tiktokshop [done]
6. page list toko, tambahkan fitur hapus toko, tapi harus ada modal konfirmasi dlu, lalu tombol otorisasi ulang, jika toko saat ini terhubung, munculkan modal peringatan "Status toko terhubung, apakah anda ingin tetap melanjutkan?" [done]
7. 


### New device / clone / git remote
instal:
- php
- composer
- node
- npm
- postgresql

pindahkan db
- finesheet-db

cmd:
- composer update
- composer install
- npm install
- cp .env.example .env
- php artisan key:generate
- php artisan migrate


jika pertama kali instal php, di php.ini ubah:
;extension=fileinfo
;extension=zip
;extension=pdo_pgsql
;extension=pgsql

jadi 

extension=fileinfo
extension=zip
extension=pdo_pgsql
extension=pgsql


atur ngrok callback: platform -> app & service -> manage


### jika ngrok link nya ganti, beberapa hal yang perlu di ganti:
- di env, tiktok redirect url
- di shopee controller, shopee redirect url
- di platform tiktok maupun shopee, untuk redirect url dan webhook
