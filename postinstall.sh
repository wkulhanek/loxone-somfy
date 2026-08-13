#!/bin/bash

COMMAND=$0
PTEMPDIR=$1
PSHNAME=$2
PDIR=$3
PVERSION=$4

echo "<INFO> Command is: $COMMAND"
echo "<INFO> Plugin name is: $PSHNAME"
echo "<INFO> Plugin folder is: $PDIR"
echo "<INFO> Plugin version is: $PVERSION"

PCONFIG=$LBHOMEDIR/config/plugins/$PDIR
BACKUPDIR="/tmp/${PTEMPDIR}_upgrade"

# Restore config from preupgrade.sh backup
if [ -d "$BACKUPDIR/config" ]; then
    echo "<INFO> Restoring config from upgrade backup..."
    cp -v -r "$BACKUPDIR/config/"* "$PCONFIG/" 2>/dev/null
    rm -rf "$BACKUPDIR"
    echo "<OK> Config restored from backup"
elif [ ! -f "$PCONFIG/somfy.json" ]; then
    echo "<INFO> Fresh install, creating default configuration..."
    cp "$PTEMPDIR/config/somfy.json" "$PCONFIG/somfy.json"
else
    echo "<INFO> Configuration already exists, keeping it"
fi

echo "<OK> Post-install completed"
exit 0
