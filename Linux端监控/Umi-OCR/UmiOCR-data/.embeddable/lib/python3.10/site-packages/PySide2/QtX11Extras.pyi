# This Python file uses the following encoding: utf-8
#############################################################################
##
## Copyright (C) 2020 The Qt Company Ltd.
## Contact: https://www.qt.io/licensing/
##
## This file is part of Qt for Python.
##
## $QT_BEGIN_LICENSE:LGPL$
## Commercial License Usage
## Licensees holding valid commercial Qt licenses may use this file in
## accordance with the commercial license agreement provided with the
## Software or, alternatively, in accordance with the terms contained in
## a written agreement between you and The Qt Company. For licensing terms
## and conditions see https://www.qt.io/terms-conditions. For further
## information use the contact form at https://www.qt.io/contact-us.
##
## GNU Lesser General Public License Usage
## Alternatively, this file may be used under the terms of the GNU Lesser
## General Public License version 3 as published by the Free Software
## Foundation and appearing in the file LICENSE.LGPL3 included in the
## packaging of this file. Please review the following information to
## ensure the GNU Lesser General Public License version 3 requirements
## will be met: https://www.gnu.org/licenses/lgpl-3.0.html.
##
## GNU General Public License Usage
## Alternatively, this file may be used under the terms of the GNU
## General Public License version 2.0 or (at your option) the GNU General
## Public license version 3 or any later version approved by the KDE Free
## Qt Foundation. The licenses are as published by the Free Software
## Foundation and appearing in the file LICENSE.GPL2 and LICENSE.GPL3
## included in the packaging of this file. Please review the following
## information to ensure the GNU General Public License requirements will
## be met: https://www.gnu.org/licenses/gpl-2.0.html and
## https://www.gnu.org/licenses/gpl-3.0.html.
##
## $QT_END_LICENSE$
##
#############################################################################

"""
This file contains the exact signatures for all functions in module
PySide2.QtX11Extras, except for defaults which are replaced by "...".
"""

# Module PySide2.QtX11Extras
import PySide2
try:
    import typing
except ImportError:
    from PySide2.support.signature import typing
from PySide2.support.signature.mapping import (
    Virtual, Missing, Invalid, Default, Instance)

class Object(object): pass

import shiboken2 as Shiboken
Shiboken.Object = Object

import PySide2.QtCore
import PySide2.QtX11Extras


class QX11Info(Shiboken.Object):
    PeekDefault              : QX11Info = ... # 0x0
    PeekFromCachedIndex      : QX11Info = ... # 0x1

    class PeekOption(object):
        PeekDefault              : QX11Info.PeekOption = ... # 0x0
        PeekFromCachedIndex      : QX11Info.PeekOption = ... # 0x1

    class PeekOptions(object): ...
    @staticmethod
    def appDpiX(screen:int=...) -> int: ...
    @staticmethod
    def appDpiY(screen:int=...) -> int: ...
    @staticmethod
    def appRootWindow(screen:int=...) -> int: ...
    @staticmethod
    def appScreen() -> int: ...
    @staticmethod
    def appTime() -> int: ...
    @staticmethod
    def appUserTime() -> int: ...
    @staticmethod
    def generatePeekerId() -> int: ...
    @staticmethod
    def getTimestamp() -> int: ...
    @staticmethod
    def isCompositingManagerRunning(screen:int=...) -> bool: ...
    @staticmethod
    def isPlatformX11() -> bool: ...
    @staticmethod
    def nextStartupId() -> PySide2.QtCore.QByteArray: ...
    @staticmethod
    def removePeekerId(peekerId:int) -> bool: ...
    @staticmethod
    def setAppTime(time:int) -> None: ...
    @staticmethod
    def setAppUserTime(time:int) -> None: ...
    @staticmethod
    def setNextStartupId(id:PySide2.QtCore.QByteArray) -> None: ...

# eof
