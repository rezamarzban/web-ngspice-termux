#!/data/data/com.termux/files/usr/bin/bash

# Update and upgrade packages non-interactively
pkg update && pkg upgrade -y

# Install required packages non-interactively, forcing to keep old config files
apt install -y -o Dpkg::Options::="--force-confold" wget openssl ngspice

# Download server.sh script
wget https://raw.githubusercontent.com/rezamarzban/web-ngspice-termux/refs/heads/main/setup/server.sh

# Make server.sh executable
chmod +x server.sh

# Run server.sh
./server.sh

# Download index.php
wget https://raw.githubusercontent.com/rezamarzban/web-ngspice-termux/refs/heads/main/index.php

# Set index.php to 755 permission (note: -c is verbose, but -v for verbose if needed; removing -c as it's not standard for chmod)
chmod 755 index.php

# Copy index.php to nginx html directory
cp index.php /data/data/com.termux/files/usr/share/nginx/html

# Display completion message
echo "Setup completed successfully!"
