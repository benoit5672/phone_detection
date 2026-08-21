#!/usr/bin/env python3
# -*- coding:utf-8 -*-
#
# Permission is hereby granted, free of charge, to any person obtaining a copy
# of this software and associated documentation files (the "Software"), to deal
# in the Software without restriction, including without limitation the rights
# to use, copy, modify, merge, publish, distribute, sublicense, and/or sell copies
# of the Software, and to permit persons to whom the Software is furnished to do so,
# subject to the following conditions:
#
# The above copyright notice and this permission notice shall be included in all copies
# or substantial portions of the Software.
#
# THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
# IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
# FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
# AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER LIABILITY,
# WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM, OUT OF OR
# IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE SOFTWARE
#
"""
2026/07/31: Benoit Rech
Classe extraite du fichier principal pour plus de clarte.
"""

import fcntl
import struct
import subprocess
import socket
import logging
import os

HCIGETDEVLIST = 0x800448d2
HCIGETDEVINFO = 0x800448d3
HCIDEVUP = 0x400448c9
HCI_MAX_DEV = 16
HCI_UP = (1 << 0)

HCI_COMMAND_PKT = 0x01
HCI_EVENT_PKT = 0x04
EVT_CMD_COMPLETE = 0x0E
OGF_HOST_CTL = 0x03
OCF_WRITE_PAGE_TIMEOUT = 0x0018
SOL_HCI = 0
HCI_FILTER = 2

class BluetoothController:
    def __init__(self, adapter, pageTimeout):
        self.adapter = adapter
        self.btId = int(self.adapter[3:])
        self.pageTimeout = pageTimeout


    def __readOsRelease(self):
        data = {}
        for path in ("/etc/os-release", "/usr/lib/os-release"):
            if not os.path.exists(path):
                continue
            try:
                with open(path, "r") as f:
                    for line in f:
                        line = line.strip()
                        if not line or line.startswith("#") or "=" not in line:
                            continue
                        k, v = line.split("=", 1)
                        data[k] = v.strip().strip('"')
                if data:
                    return data
            except Exception as e:
                logging.warning("Unable to read %s: %s".format(path, e))
        return {}

    def __getDebianMajorVersion(self):
        osr = self.__readOsRelease()
        if osr.get("ID", "").lower() == "debian":
            versionId = osr.get("VERSION_ID", "")
            if versionId:
                try:
                    return int(versionId.split(".")[0])
                except ValueError:
                    pass

        try:
            with open("/etc/debian_version", "r") as f:
                return int(f.read().strip().split(".")[0])
        except Exception:
            return None


    def __getBluetoothMode(self):
        debianMajor = self.__getDebianMajorVersion()

        logging.info("Detected Debian major version {}".format(debianMajor))

        if debianMajor == 11:
            return "legacy11"
        if debianMajor == 12:
            return "legacy12"
        if debianMajor is not None and debianMajor >= 13:
            return "python13"
        return "legacy12"


    def __setBluetoothPageTimeoutLegacy(self):
        try:
            subprocess.run(["hciconfig", self.adapter, "pageto", str(self.pageTimeout)], check=True)
            logging.info("PageTimeout set to {}s for controller {}.".format(self.pageTimeout * 0.000625, self.adapter))
            return 0
        except Exception as e:
            logging.error("Unable to set PageTimeout to {}s for controller {}: {}".format(self.pageTimeout * 0.000625, self.adapter, e))
            return -1


    def __isHciInterfaceUpLegacy(self):
        try:
            result = subprocess.run(["hciconfig", self.adapter], stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)
            if result.returncode == 0 and "UP RUNNING" in result.stdout:
                return True
            return False
        except Exception as e:
            logging.error("Error checking HCI interface status for {}: {}".format(self.adapter, e))
            return False


    def __setHciInterfaceUpLegacy(self):
        try:
            subprocess.run(["hciconfig", self.adapter, "up"], check=True)
            return True
        except subprocess.CalledProcessError as e:
            logging.error("Error bringing HCI interface up {}: {}".format(self.adapter, e))
            return False


    def __openHciSocket(self):
        return socket.socket(socket.AF_BLUETOOTH, socket.SOCK_RAW, socket.BTPROTO_HCI)


    def __listHciInterfaces(self):
        sock = self.__openHciSocket()
        try:
            buf = bytearray(struct.pack("H", HCI_MAX_DEV) + b"\x00" * (HCI_MAX_DEV * 8))
            fcntl.ioctl(sock.fileno(), HCIGETDEVLIST, buf, True)

            devNum = struct.unpack_from("H", buf, 0)[0]
            adapters = []
            offset = 2
            for _ in range(devNum):
                devId, devOpt = struct.unpack_from("HI", buf, offset)
                adapters.append(devId)
                offset += 8
            return adapters
        finally:
            sock.close()


    def __isHciInterfacePresent(self):
        return self.btId in self.__listHciInterfaces()


    def __isHciInterfaceUp(self):
        sock = self.__openHciSocket()
        try:
            buf = bytearray(struct.pack("H8s6sIB3x8I", self.btId, b"", b"", 0, 0, *([0] * 8)))
            fcntl.ioctl(sock.fileno(), HCIGETDEVINFO, buf, True)
            unpacked = struct.unpack("H8s6sIB3x8I", bytes(buf))
            flags = unpacked[3]
            return bool(flags & HCI_UP)
        except OSError as e:
            logging.warning("HCIGETDEVINFO failed for : {}".format(self.adapter, e))
            return None
        finally:
            sock.close()


    def __hciOpcodePack(self, ogf, ocf):
        return (ocf & 0x03ff) | (ogf << 10)


    def __buildHciFilterCmdComplete(self, opcode):
        typeMask = 1 << HCI_EVENT_PKT
        eventMask1 = 1 << EVT_CMD_COMPLETE
        eventMask2 = 0
        return struct.pack("IIIh2x", typeMask, eventMask1, eventMask2, opcode)


    def __setBluetoothPageTimeout(self):
        sock = self.__openHciSocket()
        oldFilter = None

        try:
            sock.bind((self.btId,))
            sock.settimeout(3.0)

            opcode = self.__hciOpcodePack(OGF_HOST_CTL, OCF_WRITE_PAGE_TIMEOUT)

            oldFilter = sock.getsockopt(SOL_HCI, HCI_FILTER, 16)
            sock.setsockopt(SOL_HCI, HCI_FILTER, self.__buildHciFilterCmdComplete(opcode))

            payload = struct.pack("<H", self.pageTimeout)
            packet = struct.pack("<BHB", HCI_COMMAND_PKT, opcode, len(payload)) + payload
            sock.send(packet)

            while True:
                data = sock.recv(260)
                if len(data) < 7 or data[0] != HCI_EVENT_PKT or data[1] != EVT_CMD_COMPLETE:
                    continue

                returnedOpcode = struct.unpack("<H", data[4:6])[0]
                if returnedOpcode != opcode:
                    continue

                status = data[6]
                if status == 0x00:
                    logging.info("PageTimeout set to {}s for controller {}.".format(self.pageTimeout * 0.000625, self.adapter))
                    return 0

                logging.error("Write_Page_Timeout failed for {}, status=0x{:02x}".format(self.adapter, status))
                return -1

        except (socket.timeout, OSError) as e:
            logging.error("Unable to set PageTimeout on {}: {}".format(self.adapter, e))
            return -1
        finally:
            if oldFilter is not None:
                try:
                    sock.setsockopt(SOL_HCI, HCI_FILTER, oldFilter)
                except Exception:
                    pass
            sock.close()


    def getAdapter(self):
        return self.adapter


    def getId(self):
        return self.btId
    
    def checkAndInit(self):

        btMode = self.__getBluetoothMode()
        logging.info("Bluetooth mode selected: {}".format(btMode))

        if btMode in ("legacy11", "legacy12"):
            if self.__isHciInterfaceUpLegacy() == True:
                logging.info('HCI interface {} is already UP.'.format(self.adapter))
            elif self.__setHciInterfaceUpLegacy() == True:
                logging.warning('HCI interface {} was down, and has been brought UP.'.format(self.adapter))
            else:
                logging.critical('Interface {} is down and status cannot be changed.'.format(self.adapter))
                return False

            if self.__setBluetoothPageTimeoutLegacy() == -1:
                return False

        elif btMode == "python13":
            if not self.__isHciInterfacePresent():
                logging.critical('No Bluetooth controller found as {}.'.format(self.adapter))
                return False

            upState = self.__isHciInterfaceUp()
            logging.info('HCI interface {} current UP state: {}'.format(self.adapter, upState))

            if upState is False:
                if self.__setHciInterfaceUpLegacy() == True:
                    logging.warning('HCI interface {} was down, and has been brought UP.'.format(self.adapter))
                else:
                    logging.critical('Interface {} is down and status cannot be changed.'.format(self.adapter))
                    return False
            elif upState is True:
                logging.info('HCI interface {} is already UP, HCIDEVUP skipped.'.format(self.adapter))
            else:
                logging.warning('HCI interface {} UP state unknown, HCIDEVUP skipped.'.format(self.adapter))

            if self.__setBluetoothPageTimeout() == -1:
                return False

        else:
            logging.critical("Unknown Bluetooth mode {}".format(btMode))
            return False

        return True


    def softResetBluetoothAdapter(self):
        # Soft reset HCI
        subprocess.run(["sudo", "hciconfig", self.adapter, "reset"], check=False)


    def hardResetBluetoothAdapter(self):

        # Reload btusb module
        subprocess.run(["sudo", "systemctl", "stop", "bluetooth"], check=False)
        subprocess.run(["sudo", "hciconfig", self.adapter, "down"], check=False)
        subprocess.run(["sudo", "rmmod", "btusb"], check=False)
        subprocess.run(["sudo", "modprobe", "btusb"], check=True)
        subprocess.run(["sudo", "hciconfig", self.adapter, "up"], check=False)
        subprocess.run(["sudo", "systemctl", "start", "bluetooth"], check=False)

