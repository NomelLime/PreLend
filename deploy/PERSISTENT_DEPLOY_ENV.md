# Постоянные переменные деплоя

Чтобы не передавать параметры в командной строке каждый раз, создай файл:

`/etc/default/prelend-deploy`

Пример:

```bash
sudo install -m 600 -o root -g root /dev/stdin /etc/default/prelend-deploy <<'EOF'
PRELEND_DOMAIN=yourdomain.me
PRELEND_SOPS_ROOT=/root/sops-secrets
SOPS_AGE_KEY_FILE=/etc/prelend/age.key
MAXMIND_LICENSE_KEY=your_maxmind_license_key
EOF
```

После этого запуск:

```bash
cd /var/www/prelend
git pull
sudo bash deploy/vps_one_command.sh
```

Оба скрипта (`deploy/vps_one_command.sh` и `deploy/deploy.sh`) автоматически подхватывают этот файл, если он существует.
