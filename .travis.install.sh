#!/usr/bin/env bash

composer self-update --stable

# Install Composer dependencies.
composer install

# Install npm dependencies.
source ~/.nvm/nvm.sh
nvm install "$NODE_VERSION"
nvm use "$NODE_VERSION"

echo "Updating npm..."
npm update -g npm

echo "Installing npm dependencies..."
npm install
