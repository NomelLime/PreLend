# Обновление уже настроенного сервера после `git pull`

Репозиторий ожидает, что PHP-FPM для PreLend слушает **отдельный сокет** пула `[prelend]`:

- **Было (старый шаблон):** `unix:/run/php/php8.3-fpm.sock` — тот же путь, что у стандартного пула `www` из пакета Ubuntu. Два пула на один сокет дают ошибку конфигурации FPM или непредсказуемый 502.
- **Стало:** `unix:/run/php/php8.3-fpm-prelend.sock` — только пул `prelend.conf`; `www` может остаться на `php8.3-fpm.sock` для других сайтов.

После `git pull` **файлы в `/etc/nginx/` и `/etc/php/.../pool.d/` сами не меняются** — их правит только `deploy.sh` или руки. Если на VPS nginx всё ещё указывает на старый сокет, а FPM уже слушает новый (или наоборот), получите **502 Bad Gateway**.

Полный **`deploy/deploy.sh`** сначала пишет пул PHP-FPM и делает **`restart`**, затем генерирует nginx и **`reload`** — сокет к моменту первого запроса через nginx уже существует.

Ниже — пошаговая проверка и выравнивание. Версию PHP подставь свою (ниже везде **8.3**).

---

## 1. Узнать, какой конфиг nginx обслуживает сайт

Часто это один из файлов:

- `/etc/nginx/sites-available/prelend`
- `/etc/nginx/sites-enabled/prelend` (симлинк)
- или имя домена: `sites-available/pulsority.com` и т.п.

Найти все упоминания сокета PHP:

```bash
sudo grep -R "fastcgi_pass" /etc/nginx/sites-enabled/ /etc/nginx/sites-available/ 2>/dev/null
```

Запомни **полный путь** к файлу, где для твоего домена стоят директивы `fastcgi_pass`.

---

## 2. Проверить пул PHP-FPM PreLend

```bash
sudo sed -n '1,40p' /etc/php/8.3/fpm/pool.d/prelend.conf
```

Должно быть:

- секция **`[prelend]`**;
- строка вида **`listen = /run/php/php8.3-fpm-prelend.sock`** (или тот путь, который ты осознанно выбрал).

Проверка синтаксиса всех пулов:

```bash
sudo php-fpm8.3 -t
```

Если тут ошибка — сначала исправь `prelend.conf` (опечатки вроде `list` вместо `listen`, обрезанный путь, лишние символы), затем:

```bash
sudo systemctl restart php8.3-fpm
sudo systemctl status php8.3-fpm --no-pager
```

---

## 3. Убедиться, что сокет появился после старта FPM

```bash
ls -la /run/php/*.sock
```

Должен быть файл **`/run/php/php8.3-fpm-prelend.sock`** (права обычно `srw-rw----`, владелец `www-data` или твой `user` пула).

Если сокета нет при `active` у `php8.3-fpm` — смотри журнал:

```bash
sudo journalctl -u php8.3-fpm -n 50 --no-pager
```

---

## 4. Выровнять nginx под тот же сокет

Открой **тот** файл сайта, который нашёл в п.1 (пример):

```bash
sudo nano /etc/nginx/sites-available/prelend
```

Найди **все** вхождения **`fastcgi_pass`**, которые относятся к PreLend. Обычно их **два**:

1. в блоке **`location = /postback.php`**;
2. в блоке **`location ~ \.php$`** (или аналог).

Замени старый путь на новый **одинаково в обоих местах**:

```nginx
fastcgi_pass unix:/run/php/php8.3-fpm-prelend.sock;
```

Рекомендуется также не оставлять слишком маленький таймаут (иначе долгие запросы обрежутся раньше PHP):

```nginx
fastcgi_read_timeout 90s;
```

в тех же `location`, где `fastcgi_pass` (как в эталоне `deploy/nginx.conf`).

Сохрани файл.

**Важно:** правки делаются **в этом файле на диске**, а не вводом строк `listen=` / `fastcgi_pass` в интерактивной оболочке bash — в shell эти строки конфиг не меняют.

---

## 5. Проверить и перезагрузить nginx

```bash
sudo nginx -t
```

Ожидается: `syntax is ok` / `test is successful`.

```bash
sudo systemctl reload nginx
```

После **смены пути `listen =` в pool** надёжнее **`systemctl restart php8.3-fpm`**, а не только `reload` (чтобы мастер гарантированно пересоздал сокет).

---

## 5a. Симптом: `502` и в логе `connect() ... failed (111: Connection refused)`

Сообщение **`111: Connection refused`** к сокету `unix:/run/php/php8.3-fpm.sock` значит: nginx дошёл до пути сокета, но **на этом адресе сейчас нет слушающего процесса** (не «нет файла», как при коде **2**).

Чаще всего одно из двух:

1. **Служба `php8.3-fpm` не запущена или упала** (ошибка в pool-конфиге после правок, неверный синтаксис и т.п.).
2. **Nginx смотрит на `php8.3-fpm.sock`, а единственный рабочий пул PreLend слушает `php8.3-fpm-prelend.sock`**, при этом пул `www` отключён или не создаёт старый сокет — тогда файл сокета может отсутствовать или сокет есть, но accept не идёт (зависит от состояния; после отключения `www` типично именно отказ, если master не слушает там).

Проверка по порядку (на сервере под root/sudo):

```bash
# Статус и последние ошибки FPM
sudo systemctl status php8.3-fpm --no-pager
sudo journalctl -u php8.3-fpm -n 40 --no-pager

# Синтаксис всех пулов (обязательно после любых правок pool.d)
sudo php-fpm8.3 -t

# Какие сокеты реально есть
ls -la /run/php/*.sock 2>/dev/null || true

# Куда смотрит nginx
sudo grep -R "fastcgi_pass" /etc/nginx/sites-enabled/ /etc/nginx/sites-available/ 2>/dev/null
```

**Действия:**

- Если **`php-fpm8.3 -t` или `systemctl status` показывают ошибку** — исправь файл пула (частые причины: опечатка `list` вместо `listen`, обрезанная строка, два пула с одним `listen`). Затем `sudo systemctl restart php8.3-fpm` и снова `ls -la /run/php/*.sock`.
- Если **FPM active**, в `pool.d/prelend.conf` указано **`listen = .../php8.3-fpm-prelend.sock`**, а в nginx всё ещё **`fastcgi_pass unix:.../php8.3-fpm.sock`** — выровняй nginx на **`...-prelend.sock`** (оба `location` с PHP, см. §4), затем `nginx -t` и `reload`.

После исправления снова проверь `curl -sI https://домен/`.

---

## 6. Быстрая проверка с сервера

```bash
curl -sI https://ТВОЙ_ДОМЕН/ | head -5
```

Ожидается **200** или редирект **301/302**, не **502**.

При проблемах:

```bash
sudo tail -n 40 /var/log/nginx/prelend_error.log
# или error_log того server{}, который ты правил
```

Типичные строки в логе:

- **`(111: Connection refused)`** — на этом сокете никто не слушает или FPM не поднялся; см. **§5a**.
- **`(2: No such file or directory)`** — неверный путь или сокет ещё не создан (FPM не стартовал).
- Ошибки прав доступа к сокету — пользователь nginx и `listen.group` / `listen.mode` в пуле.

---

## Альтернатива: перегенерировать сайт через `deploy.sh`

Если устраивает **полная перезапись** nginx-конфига, который пишет скрипт (путь по умолчанию `/etc/nginx/sites-available/prelend`):

```bash
cd /var/www/prelend
sudo PRELEND_DOMAIN=твойдомен.tld bash deploy/deploy.sh
```

Учти: скрипт делает много шагов (apt, git pull, cron, certbot при смене домена и т.д.). Для **только** сокета безопаснее ручное редактирование по п.4–5.

Эталонные блоки без запуска всего деплоя можно сверить с файлами в репозитории:

- `deploy/nginx.conf`
- фрагмент nginx в `deploy/deploy.sh` (heredoc после «Настраиваем Nginx»).

---

## Про `status.md` и git

В корне PreLend файл **`status.md`** часто используют как **локальные заметки сессии** (чеклисты, пароли не кладут туда; секреты — только в `.env` и в конфигах на сервере).

- Если **`status.md` в `.gitignore`** — изменения остаются только у тебя на машине, в удалённый репозиторий не попадут.
- Если файла **нет** в `.gitignore` и он закоммичен — решай сам: либо продолжай коммитить как журнал проекта, либо добавь в `.gitignore` и перестань трекать (`git rm --cached status.md`).

Инструкции для прод-сервера, которые должны жить в git, разумно держать в **`deploy/*.md`** (как этот файл), а не только в `status.md`.

---

## Internal API: `systemctl` показывает `status=203/EXEC`

**203/EXEC** значит: systemd **не смог выполнить** команду из **`ExecStart`** (чаще всего **нет файла** `/var/www/prelend/venv/bin/uvicorn`).

Проверка:

```bash
ls -la /var/www/prelend/venv/bin/uvicorn
file /var/www/prelend/venv/bin/uvicorn
```

Если файла нет — создай venv и поставь зависимости Internal API:

```bash
cd /var/www/prelend
sudo -u www-data python3 -m venv /var/www/prelend/venv
sudo -u www-data /var/www/prelend/venv/bin/pip install --upgrade pip
sudo -u www-data /var/www/prelend/venv/bin/pip install -r internal_api/requirements.txt
```

Убедись, что в **`/etc/systemd/system/prelend-internal-api.service`** **`User`/`Group`** совпадают с владельцем **`/var/www/prelend`** (часто **`www-data`**). После правок unit:

```bash
sudo systemctl daemon-reload
sudo systemctl restart prelend-internal-api
sudo systemctl status prelend-internal-api --no-pager
```

Полный деплой из актуального **`deploy/deploy.sh`** сам создаёт **`venv`** и ставит **`internal_api/requirements.txt`**.
