#!/bin/bash

COMMAND=$0
PTEMPDIR=$1
PSHNAME=$2
PDIR=$3
PVERSION=$4

VENVDIR="$LBHOMEDIR/data/plugins/$PDIR/venv"

echo "<INFO> Creating Python virtual environment..."
python3 -m venv "$VENVDIR" 2>&1

if [ $? -ne 0 ]; then
    echo "<FAIL> Failed to create virtual environment"
    exit 2
fi

echo "<INFO> Installing Python dependencies into venv..."
"$VENVDIR/bin/pip" install paho-mqtt requests 2>&1

if [ $? -ne 0 ]; then
    echo "<FAIL> Failed to install Python dependencies"
    exit 2
fi

echo "<INFO> Setting daemon executable..."
chmod +x "$LBHOMEDIR/bin/plugins/$PDIR/somfy_daemon.py"

echo "<OK> Post-root installation completed"
exit 0
