#!/bin/bash
export PYTHONHOME=$PWD
export PATH=$PWD/bin:$PATH
if [ ! -z "$LD_LIBRARY_PATH" ] ; then
    export LD_LIBRARY_PATH="$LD_LIBRARY_PATH:"
fi
export LD_LIBRARY_PATH="$PWD/lib:$LD_LIBRARY_PATH"
