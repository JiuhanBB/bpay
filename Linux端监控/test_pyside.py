import sys
sys.path.insert(0, '/app/Umi-OCR/UmiOCR-data/py_src')
from PySide2.QtCore import QStandardPaths
print('OK:', QStandardPaths.writableLocation(0))
