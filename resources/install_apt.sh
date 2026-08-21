#!/bin/bash
PROGRESS_FILE=/tmp/jeedom/phone_detection/dependance
if [ ! -z $1 ]; then
	PROGRESS_FILE=$1
fi
touch ${PROGRESS_FILE}
echo 0 > ${PROGRESS_FILE}
echo "********************************************************"
echo "*			 Installation des dépendances			 *"
echo "********************************************************"
sudo apt-get update
echo 40 > ${PROGRESS_FILE}
sudo apt-get install -y bluetooth rfkill
echo 60 > ${PROGRESS_FILE}
sudo apt-get install -y python3 bluez bluez-hcidump python3-pip --reinstall
echo 80 > ${PROGRESS_FILE}
sudo pip3 install requests  --break-system-packages
sudo apt-get install -y python3-paho-mqtt --reinstall
echo 90 > ${PROGRESS_FILE}
sudo rfkill unblock 0 >/dev/null 2>&1
sudo rfkill unblock 1 >/dev/null 2>&1
sudo rfkill unblock 2 >/dev/null 2>&1
sudo hciconfig hci0 up >/dev/null 2>&1
sudo hciconfig hci1 up >/dev/null 2>&1
sudo hciconfig hci2 up >/dev/null 2>&1
echo 100 > ${PROGRESS_FILE}
sudo usermod -aG bluetooth www-data
echo "********************************************************"
echo "*			 Installation terminée					*"
echo "********************************************************"
rm -f ${PROGRESS_FILE}