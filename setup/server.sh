#!/data/data/com.termux/files/usr/bin/bash

# Update and upgrade Termux packages
pkg update -y && pkg upgrade -y

# Install necessary packages
pkg install nginx php php-fpm nano -y  # nano for editing if needed, but we'll automate configs

# Configure PHP-FPM
PHP_FPM_CONF="$PREFIX/etc/php-fpm.d/www.conf"
sed -i 's/listen = 127.0.0.1:9000/listen = \/data\/data\/com.termux\/files\/usr\/var\/run\/php-fpm.sock/g' $PHP_FPM_CONF
sed -i 's/^listen.owner = nobody/;listen.owner = nobody/g' $PHP_FPM_CONF
sed -i 's/^listen.group = nobody/;listen.group = nobody/g' $PHP_FPM_CONF
sed -i 's/;listen.mode = 0660/listen.mode = 0666/g' $PHP_FPM_CONF

# Configure Nginx
NGINX_CONF="$PREFIX/etc/nginx/nginx.conf"
cat > $NGINX_CONF << EOF
worker_processes  1;
events {
    worker_connections  1024;
}

http {
    include       mime.types;
    default_type  application/octet-stream;
    sendfile        on;
    keepalive_timeout  65;

    server {
        listen       8080;
        server_name  localhost;
        root         /data/data/com.termux/files/usr/share/nginx/html;
        index        index.php index.html index.htm;

        location / {
            try_files \$uri \$uri/ =404;
        }

        location ~ \.php\$ {
            include        fastcgi_params;
            fastcgi_pass   unix:/data/data/com.termux/files/usr/var/run/php-fpm.sock;
            fastcgi_index  index.php;
            fastcgi_param  SCRIPT_FILENAME  \$document_root\$fastcgi_script_name;
        }
    }
}
EOF

# Set up web directory and test files
WEB_DIR="$PREFIX/share/nginx/html"
mkdir -p $WEB_DIR

echo "<?php phpinfo(); ?>" > $WEB_DIR/info.php

cat > $WEB_DIR/index.html << EOF
<!DOCTYPE html>
<html>
<head>
    <title>Welcome to Termux Server</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 40px; }
        h1 { color: #333; }
    </style>
</head>
<body>
    <h1>Nginx is working on Termux!</h1>
    <p>Your web server is successfully running on Android.</p>
    <p><a href="info.php">Check PHP information</a></p>
</body>
</html>
EOF

# Start services
pkill php-fpm  # Stop if already running
pkill nginx
php-fpm &
nginx

echo "Setup complete! Access the server at http://localhost:8080"
echo "To stop: pkill php-fpm && nginx -s stop"
