#!/bin/sh
# postupgrade.sh - Panasonic TV Plugin

ARGV0=$0
ARGV1=$1 # temp folder
ARGV2=$2 # Plugin short name (NAME)
ARGV3=$3 # Plugin installation folder (FOLDER)
ARGV4=$4 # Plugin version
ARGV5=$5 # Base folder of LoxBerry

if [ -f /etc/environment ]; then
    . /etc/environment
fi

LBHOMEDIR=${LBHOMEDIR:-$ARGV5}
PDIR=$ARGV3
PDATA=${LBPDATA}/$PDIR
PBIN=${LBPBIN}/$PDIR
PDAEMON=${LBPDAEMON}/$PDIR

echo "<INFO> Panasonic TV postupgrade: Version $ARGV4"

# Restore config if backup exists
if [ -d "/tmp/${ARGV1}_upgrade/config/$PDIR" ]; then
    echo "<INFO> Stelle Panasonic TV Konfiguration wieder her..."
    cp -v -r /tmp/${ARGV1}_upgrade/config/$PDIR/* $LBHOMEDIR/config/plugins/$PDIR/ 2>/dev/null
fi

# Ensure Python venv and permissions
VENV_DIR="$PDATA/venv"
if [ ! -d "$VENV_DIR" ]; then
    echo "<INFO> Erstelle Python Virtual Environment..."
    python3 -m venv "$VENV_DIR"
fi

if [ -f "$VENV_DIR/bin/pip" ]; then
    echo "<INFO> Aktualisiere panasonic-viera und paho-mqtt im venv..."
    "$VENV_DIR/bin/pip" install --upgrade panasonic-viera paho-mqtt
fi

if [ -d "$PBIN" ]; then
    chmod +x "$PBIN"/* 2>/dev/null
fi
if [ -d "$PDAEMON" ]; then
    chmod +x "$PDAEMON"/* 2>/dev/null
fi

# Restart daemon
if [ -x "$PDAEMON/daemon.sh" ]; then
    "$PDAEMON/daemon.sh" restart 2>&1
fi

# Clean up temp backup
rm -rf /tmp/${ARGV1}_upgrade 2>/dev/null

echo "<OK> Panasonic TV Upgrade erfolgreich abgeschlossen."
exit 0
