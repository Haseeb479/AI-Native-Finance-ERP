import sys
from pathlib import Path

# Ensure workspace root is always in sys.path for absolute imports
repo_root = Path(__file__).resolve().parent.parent.parent
if str(repo_root) not in sys.path:
    sys.path.insert(0, str(repo_root))
