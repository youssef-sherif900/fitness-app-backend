# Every request goes to Laravel's front controller; nginx needs no copy of the code.
include fastcgi_params;
fastcgi_pass app:9000;
fastcgi_param SCRIPT_FILENAME /var/www/html/public/index.php;
fastcgi_param SCRIPT_NAME /index.php;
fastcgi_read_timeout 60s;
