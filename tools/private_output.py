"""Create sensitive local report files without overwriting existing data."""
import os


def write_private_text(path, text):
    """Write UTF-8 to a new file with owner-only access, regardless of umask."""
    flags = os.O_WRONLY | os.O_CREAT | os.O_EXCL | getattr(os, 'O_NOFOLLOW', 0)
    fd = os.open(path, flags, 0o600)
    # A failed write can leave a private partial file. Keeping it avoids
    # deleting a different file if another process replaces the path.
    with os.fdopen(fd, 'w', encoding='utf-8') as target:
        target.write(text)
