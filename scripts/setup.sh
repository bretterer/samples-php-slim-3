#!/bin/bash -vx

cd ${OKTA_HOME}/${REPO}

# Revert the cache-min setting, since the internal cache does not apply to
# these repos (and causes problems in lookups)
npm config set cache-min 10

# Use newer, faster npm
npm install -g npm@4.1.2

SHRINKWRAP="$OKTA_HOME/$REPO/tools/wrap-dependencies/npm-shrinkwrap-ci.json"
if [ -f "$SHRINKWRAP" ];
then
  cp "$SHRINKWRAP" "$OKTA_HOME/$REPO/npm-shrinkwrap.json"
else
  echo "No CI shrinkwrap! Run \"npm run wrap\""
  exit $FAILED_SETUP
fi

if ! npm install; then
  echo "npm install failed! Exiting..."
  exit ${FAILED_SETUP}
fi

# Find out which version of Ubuntu we are on:
lsb_release -a

# Install PHP7, for Ubuntu 14.04
apt-get install -y language-pack-en-base
if ! hash add-apt-repository 2> /dev/null; then
    apt-get install -y software-properties-common python-software-properties
fi
LC_ALL=en_US.UTF-8 add-apt-repository ppa:ondrej/php
apt-get update
apt-get install -y php7.0 php7.0-cli php7.0-xml php7.0-mbstring php7.0-curl

if ! hash php 2>/dev/null; then
    echo "PHP 7 install failed! Exiting..."
    exit ${FAILED_SETUP}
fi

# Install composer
curl -sS https://getcomposer.org/installer | sudo php -- --install-dir=/usr/local/bin --filename=composer

if ! hash composer 2>/dev/null; then
    echo "Composer install failed! Exiting..."
    exit ${FAILED_SETUP}
fi

# Install PHP packages using Composer
cd ${OKTA_HOME}/${REPO}/src # I'm not sure how to reference the composer.lock if it's not in the cwd
composer --no-plugins --no-scripts install
cd ${OKTA_HOME}/${REPO}
