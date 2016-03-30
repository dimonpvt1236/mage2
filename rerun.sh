#!/bin/bash
sudo chmod 777 var -R 
sudo chmod 777 pub/static -R
sudo chmod 777 pub/media -R

bin/magento cache:clean
bin/magento cache:flush 
bin/magento cache:flush layout  # Flush Layout XML
bin/magento cache:flush full_page  # Flush Full Page Cache

sudo chmod 777 var -R 
sudo chmod 777 pub/static -R
sudo chmod 777 pub/media -R

bin/magento setup:upgrade

sudo chmod 777 var -R
sudo chmod 777 pub/static -R
sudo chmod 777 pub/media -R

