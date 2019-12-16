# Prima Base Cloud

1. Duplicate .env.template to .env
2. Masukkan detail database & APP_URL (localhost:8888 jika menggunakan built in php server)
3. `composer install` && `composer start` (built in php server)

## Use Case

1. [x] Auth (+ feature from prinus-admin)

2. [x] Latest record logger, distinct by SN, order by time received

	1. [x] /logger

	2. [x] /logger/{SN}

	3. [x] Ubah ukuran font

	4. [x] Ubah backlight theme (white / black)

3. [x] Configure

	1. [x] nilai constant tergantung tipe logger (ARR, Sonar/AWLR)

	2. [x] koreksi nilai logger

	3. [x] lokasi (bisa tambah baru)

	4. [x] tipe logger (ARR, Sonar)

4. [ ] Daftar lokasi

	1. [x] /location

	2. [x] /location/{ID}

	3. [x] Config lokasi (nama, tenant)

	4. [ ] LL lokasi