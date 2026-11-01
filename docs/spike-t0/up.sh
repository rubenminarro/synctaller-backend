#!/bin/bash
# Levanta 4 php-cgi y un Nginx propios del paso 0. No toca Laragon.
T="$1"
PHPCGI=/c/laragon/bin/php/<version>/php-cgi.exe
export PHP_FCGI_MAX_REQUESTS=0
for port in 9101 9102 9103 9104; do
  "$PHPCGI" -b 127.0.0.1:$port -d zend_extension=opcache -d opcache.enable=1 -d opcache.memory_consumption=192 -d opcache.max_accelerated_files=20000 &
done
sleep 1
/c/laragon/bin/nginx/nginx-1.28.2/nginx.exe -p "$T/ng/" -c conf/nginx.conf
wait
