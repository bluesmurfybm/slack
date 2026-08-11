"""pytest 가 magazine/ 을 임포트 루트로 잡게 한다."""
import os
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
