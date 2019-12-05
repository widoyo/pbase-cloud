# Prima Base Cloud

1. Duplicate .env.template to .env
2. Masukkan detail database & APP_URL (localhost:8888 jika menggunakan built in php server)
3. `composer install` && `composer start` (built in php server)

## Use Case

1. [x] Auth (+ feature from prinus-admin)

2. [ ] Latest record logger, distinct by SN, order by time received

	a. [x] /logger

	b. [ ] /logger/{SN}

3. [ ] Configure

	a. [ ] nilai constant tergantung tipe logger (ARR, Sonar)

	b. [ ] koreksi nilai logger

	c. [ ] lokasi

4. [ ] Daftar lokasi