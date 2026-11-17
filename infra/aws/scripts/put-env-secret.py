#!/usr/bin/env python3
"""Put a production .env into the Secrets Manager secret filipinohomes/api, as JSON key/value pairs.

    python infra/aws/scripts/put-env-secret.py <path/to/production.env> [--dry-run] [--yes]

The file is parsed the way Laravel parses it (a port of vlucas/phpdotenv 5.6.3, the version in
composer.lock), so every value lands in the secret exactly as the app sees it today. The secret's
whole value is replaced: keys left out of the file are removed from the secret.

Values are never printed. The script shows each key's name and length, the key names added,
removed or changed compared with the secret's current value, and asks before writing.
Uses the AWS CLI with your current credentials; needs GetSecretValue and PutSecretValue.
"""

import argparse
import json
import os
import re
import shutil
import subprocess
import sys
import tempfile
import unicodedata

SECRET_ID = "filipinohomes/api"
REGION = "ap-southeast-1"
MAX_SECRET_BYTES = 65536


class DotenvError(Exception):
    pass


# --- Parser: vlucas/phpdotenv 5.6.3 (src/Parser, src/Loader), ported line for line -------------

# PHP's trim()/ltrim() default characters (narrower than Python's strip()).
_PHP_TRIM = " \t\n\r\0\x0b"
# ctype_space() in the C locale.
_CTYPE_SPACE = " \t\n\r\x0b\x0c"

# Lexer::PATTERNS. PHP matches these on bytes without the /u flag, so \s is ASCII-only: re.ASCII.
_LEXER = re.compile(
    r"([\r\n]{1,1000})|([^\S\r\n]{1,1000})|(\\)|(')|(\")|(\#)|(\$)|((?:[^(\s\\'\"\#\$)]|\(|\)){1,1000})",
    re.ASCII,
)
_VAR = re.compile(r"\A\$\{([a-zA-Z0-9_.]+)\}")
_QUOTE_AFTER_NON_BACKSLASH = re.compile(r'(?=([^\\]"))', re.DOTALL)

INITIAL, UNQUOTED, SINGLE_QUOTED, DOUBLE_QUOTED, ESCAPE, WHITESPACE, COMMENT = range(7)
_REJECT = (SINGLE_QUOTED, DOUBLE_QUOTED, ESCAPE)


def _ctype_space(s):
    return s != "" and all(c in _CTYPE_SPACE for c in s)


def _lex(content):
    pos = 0
    while pos < len(content):
        m = _LEXER.match(content, pos)
        if not m:
            raise DotenvError(f"Lexer encountered unexpected character [{content[pos]}].")
        pos = m.end()
        yield m.group(0)


def _looks_like_multiline_stop(line, started):
    if line == '"':
        return True
    count = len(_QUOTE_AFTER_NON_BACKSLASH.findall(line.replace("\\\\", "")))
    return count > 1 if started else count >= 1


def _looks_like_multiline_start(line):
    return '="' in line and _looks_like_multiline_stop(line, True) is False


def _lines(lines):
    """Lines::process: joins double-quoted multiline values, drops blank and comment lines."""
    output, multiline, buffer = [], False, []
    for line in lines:
        starts_here = False if multiline else _looks_like_multiline_start(line)
        if starts_here:
            multiline = True
        if multiline:
            buffer.append(line)
            if _looks_like_multiline_stop(line, starts_here):
                multiline = False
                line = "\n".join(buffer)
                buffer = []
        if not multiline:
            stripped = line.strip(_PHP_TRIM)
            if not (stripped == "" or stripped[0] == "#"):
                output.append(line)
    # phpdotenv silently drops a multiline value that never closes; say so instead.
    unterminated = buffer[0].split("=", 1)[0].strip(_PHP_TRIM) if multiline and buffer else None
    return output, unterminated


def _is_valid_name(name):
    # ~(*UTF8)\A[\p{Ll}\p{Lu}\p{M}\p{N}_.]+\z~
    return name != "" and all(
        c in "_." or unicodedata.category(c) in ("Ll", "Lu", "Mn", "Mc", "Me", "Nd", "Nl", "No")
        for c in name
    )


def _parse_name(name):
    if len(name) > 8 and name[:6] == "export" and _ctype_space(name[6:7]):
        name = name[6:].lstrip(_PHP_TRIM)
    if len(name) >= 3 and ((name[0] == name[-1] == '"') or (name[0] == name[-1] == "'")):
        name = name[1:-1]
    if not _is_valid_name(name):
        raise DotenvError(f"Encountered an invalid name at [{name}].")
    return name


def _process_token(state, token):
    """EntryParser::processToken: returns (chars, is_variable, next_state)."""
    if state == INITIAL:
        if token == "'":
            return "", False, SINGLE_QUOTED
        if token == '"':
            return "", False, DOUBLE_QUOTED
        if token == "#":
            return "", False, COMMENT
        if token == "$":
            return token, True, UNQUOTED
        return token, False, UNQUOTED
    if state == UNQUOTED:
        if token == "#":
            return "", False, COMMENT
        if _ctype_space(token):
            return "", False, WHITESPACE
        if token == "$":
            return token, True, UNQUOTED
        return token, False, UNQUOTED
    if state == SINGLE_QUOTED:
        if token == "'":
            return "", False, WHITESPACE
        return token, False, SINGLE_QUOTED
    if state == DOUBLE_QUOTED:
        if token == '"':
            return "", False, WHITESPACE
        if token == "\\":
            return "", False, ESCAPE
        if token == "$":
            return token, True, DOUBLE_QUOTED
        return token, False, DOUBLE_QUOTED
    if state == ESCAPE:
        if token in ('"', "\\", "$"):
            return token, False, DOUBLE_QUOTED
        first = token[:1]
        if first in ("f", "n", "r", "t", "v"):
            return {"f": "\f", "n": "\n", "r": "\r", "t": "\t", "v": "\v"}[first] + token[1:], False, DOUBLE_QUOTED
        raise DotenvError("an unexpected escape sequence")
    if state == WHITESPACE:
        if token == "#":
            return "", False, COMMENT
        if not _ctype_space(token):
            raise DotenvError("unexpected whitespace")
        return "", False, WHITESPACE
    return "", False, COMMENT


def _parse_value(value):
    """Returns (chars, variable positions)."""
    if value.strip(_PHP_TRIM) == "":
        return "", []
    chars, var_positions, state = "", [], INITIAL
    try:
        for token in _lex(value):
            add, is_var, state = _process_token(state, token)
            if is_var:
                var_positions.append(len(chars))
            chars += add
    except DotenvError as e:
        raise DotenvError(f"Encountered {e} at [{value.split(chr(10))[0]}].") from None
    if state in _REJECT:
        raise DotenvError(f"Encountered a missing closing quote at [{value.split(chr(10))[0]}].")
    return chars, var_positions


def _resolve(chars, var_positions, loaded):
    """Resolver::resolve: ${NAME} takes the value of NAME loaded earlier in the file."""
    for i in sorted(var_positions, reverse=True):
        rest = chars[i:]
        m = _VAR.match(rest)
        if m and loaded.get(m.group(1)) is not None:
            rest = loaded[m.group(1)] + rest[m.end():]
        chars = chars[:i] + rest
    return chars


def parse_dotenv(content):
    """Parse .env content like Laravel does. Returns ({name: value or None}, unterminated name or None).

    None means the name appears without '=' (phpdotenv clears it). Later duplicates overwrite
    earlier ones, keeping the first position, as phpdotenv's array_merge does.
    """
    if content.startswith("﻿"):
        content = content[1:]
    entries, unterminated = _lines(re.split(r"\r\n|\n|\r", content))
    loaded = {}
    for raw in entries:
        if "=" in raw:
            name, value = (part.strip(_PHP_TRIM) for part in raw.split("=", 1))
        else:
            name, value = raw, None
        if name == "":
            raise DotenvError(f"Encountered an unexpected equals at [{raw.split(chr(10))[0]}].")
        name = _parse_name(name)
        if value is None:
            loaded[name] = None
        else:
            chars, var_positions = _parse_value(value)
            loaded[name] = _resolve(chars, var_positions, loaded)
    return loaded, unterminated


# --- Secrets Manager ------------------------------------------------------------------------------

def _aws(args):
    exe = shutil.which("aws")
    if not exe:
        sys.exit("The AWS CLI (aws) isn't on PATH.")
    # Output is captured, never printed: get-secret-value returns the values.
    return subprocess.run([exe, *args], capture_output=True, text=True, encoding="utf-8")


def _current_secret(secret_id, region):
    r = _aws(["secretsmanager", "get-secret-value", "--secret-id", secret_id, "--region", region,
              "--query", "SecretString", "--output", "text"])
    if r.returncode != 0:
        if "ResourceNotFoundException" in r.stderr and "AWSCURRENT" in r.stderr:
            return None  # the secret exists but has no value yet
        sys.exit(f"Couldn't read {secret_id}:\n{r.stderr.strip()}")
    try:
        return json.loads(r.stdout)
    except json.JSONDecodeError:
        sys.exit(f"{secret_id}'s current value isn't JSON; not replacing it.")


def main():
    p = argparse.ArgumentParser(description=__doc__.split("\n\n")[0])
    p.add_argument("env_file")
    p.add_argument("--secret-id", default=SECRET_ID)
    p.add_argument("--region", default=REGION)
    p.add_argument("--dry-run", action="store_true", help="parse and list key names only; don't contact AWS")
    p.add_argument("--yes", action="store_true", help="don't ask before writing")
    a = p.parse_args()

    with open(a.env_file, "rb") as f:
        raw = f.read()
    try:
        content = raw.decode("utf-8")
    except UnicodeDecodeError as e:
        sys.exit(f"{a.env_file} isn't valid UTF-8 ({e}).")
    try:
        parsed, unterminated = parse_dotenv(content)
    except DotenvError as e:
        sys.exit(f"Laravel couldn't parse this file either: {e}")
    if unterminated:
        sys.exit(f"{unterminated} opens a double-quoted value that never closes; Laravel would drop it. Fix the file first.")

    cleared = [k for k, v in parsed.items() if v is None]
    values = {k: v for k, v in parsed.items() if v is not None}

    print(f"{len(values)} settings in {a.env_file} (values not shown):")
    width = max((len(k) for k in values), default=0)
    for k, v in values.items():
        notes = []
        if v == "":
            notes.append("empty")
        if "${" in v:
            notes.append("contains an unresolved ${...}")
        print(f"  {k.ljust(width)}  {len(v):>5} chars" + (f"  ({', '.join(notes)})" if notes else ""))
    if cleared:
        print(f"Left out, named without '=': {', '.join(cleared)}")

    body = json.dumps(values, ensure_ascii=True, separators=(",", ":"))
    if len(body.encode()) > MAX_SECRET_BYTES:
        sys.exit(f"The JSON is {len(body.encode())} bytes; Secrets Manager's limit is {MAX_SECRET_BYTES}.")
    if a.dry_run:
        return

    current = _current_secret(a.secret_id, a.region)
    if current is None:
        print(f"\n{a.secret_id} has no value yet; this writes its first version.")
    else:
        added = [k for k in values if k not in current]
        removed = [k for k in current if k not in values]
        changed = [k for k in values if k in current and current[k] != values[k]]
        print(f"\nCompared with {a.secret_id}'s current value:")
        print(f"  added:   {', '.join(added) or '-'}")
        print(f"  removed: {', '.join(removed) or '-'}")
        print(f"  changed: {', '.join(changed) or '-'}")
        if not (added or removed or changed):
            print("Nothing to change.")
            return

    if not a.yes and input(f"\nReplace {a.secret_id} with these {len(values)} settings? [y/N] ").strip().lower() != "y":
        print("Nothing written.")
        return

    fd, path = tempfile.mkstemp(suffix=".json")
    try:
        with os.fdopen(fd, "w", encoding="ascii") as f:
            f.write(body)
        r = _aws(["secretsmanager", "put-secret-value", "--secret-id", a.secret_id, "--region", a.region,
                  "--secret-string", "file://" + path.replace("\\", "/"),
                  "--query", "VersionId", "--output", "text"])
    finally:
        os.remove(path)
    if r.returncode != 0:
        sys.exit(f"put-secret-value failed:\n{r.stderr.strip()}")
    print(f"Written: {a.secret_id}, version {r.stdout.strip()}.")


if __name__ == "__main__":
    main()
