#!/bin/bash

# Executed as very first step BEFORE preinstall.sh during plugin upgrade.
# Backs up config files to /tmp so postinstall.sh can restore them.
# Exit code 0: success, 1: warning, 2: cancel installation.

COMMAND=$0
PTEMPDIR=$1
PSHNAME=$2
PDIR=$3
PVERSION=$4

PCONFIG=$LBPCONFIG/$PDIR

BACKUPDIR="/tmp/${PTEMPDIR}_upgrade"

echo "<INFO> Backing up config files before upgrade..."
mkdir -p "$BACKUPDIR/config"
if [ -d "$PCONFIG" ]; then
    cp -v -r "$PCONFIG/"* "$BACKUPDIR/config/" 2>/dev/null
    echo "<OK> Config backed up to $BACKUPDIR/config/"
else
    echo "<WARNING> No config directory found at $PCONFIG"
fi

exit 0
