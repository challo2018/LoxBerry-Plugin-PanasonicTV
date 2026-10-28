#!/bin/bash
# preupgrade.sh - Panasonic TV Plugin

ARGV0=$0
ARGV1=$1 # temp folder
ARGV2=$2 # Plugin short name (NAME)
ARGV3=$3 # Plugin installation folder (FOLDER)
ARGV4=$4 # Plugin version
ARGV5=$5 # Base folder of LoxBerry

echo "<INFO> Creating temporary backup folders for upgrading $ARGV3..."
mkdir -p /tmp/${ARGV1}_upgrade/config

echo "<INFO> Backing up existing Panasonic TV config files..."
if [ -d "$ARGV5/config/plugins/$ARGV3" ]; then
    cp -v -r $ARGV5/config/plugins/$ARGV3/ /tmp/${ARGV1}_upgrade/config/
fi

exit 0
