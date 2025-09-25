#!/data/data/com.termux/files/usr/bin/bash

# Update and upgrade packages
pkg update && pkg upgrade -y

# Install required packages
pkg install wget openssl ngspice -y

# Download server.sh script
wget https://raw.githubusercontent.com/rezamarzban/web-ngspice-termux/refs/heads/main/setup/server.sh

# Make server.sh executable
chmod +x server.sh

# Run server.sh
./server.sh

# Download index.php
wget https://raw.githubusercontent.com/rezamarzban/web-ngspice-termux/refs/heads/main/index.php

# Copy index.php to nginx html directory
cp index.php /data/data/com.termux/files/usr/share/nginx/html

# Display completion message
echo "Setup completed successfully!"
