"""GitChronicle code analyzer: reads the files of a cloned repository with tree-sitter.

For every file it reports the language, the number of lines and, for PHP, JavaScript,
TypeScript, Python, Go, Java, Shell and Rust, the symbols (classes, functions, ...) and the imports,
each import resolved to a file of the repository when it points to one. A file without a known
extension gets its language from its shebang line (`#!/usr/bin/env bash`).

    python analyze.py /path/to/clone --files-from list.txt    # one path per line ("-" = stdin)
    python analyze.py /path/to/clone --files-from list.txt --cache parsed.json
    python analyze.py --install-grammars                       # once, at install time

The result is printed as JSON on stdout:

    {"files": [{"path", "language", "lines", "cached",
                "symbols": [{"kind", "name", "start_line", "end_line"}],
                "imports": [{"raw", "resolved_path", "external"}]}],
     "errors": [{"path", "message"}],
     "parsed": 12,
     "left_out": {"files": 0, "symbols": 0, "imports": 0}}

A repository is written by a stranger, so the analysis has limits (the defaults below, set by the caller with
--max-* options): at most so many symbols and imports per file and in total, a time limit per file and for
the whole run, and a size limit for every file read from the clone. What does not fit is left out and counted in
"left_out"; the analysis itself never fails because of it.

With --cache the analyzer remembers, in that JSON file, what it parsed from every file together
with the hash of the file's content. At the next run a file with the same content is not parsed
again ("cached": true); "parsed" counts the files that were. Only the parsing is remembered: every
import is resolved again at each run, because that depends on which other files exist. So the
result is the same as without the cache, only faster (the incremental update, Faza 9).

A file that cannot be read or parsed goes to "errors"; it never stops the others.
The analysis never uses the network: the grammars are downloaded only by --install-grammars.
"""

import argparse
import hashlib
import json
import os
import posixpath
import re
import sys
import time
import tomllib
from pathlib import Path

import tree_sitter_language_pack as language_pack

# The grammars live next to the virtual environment, so deleting analyzer/.venv resets everything.
GRAMMARS_DIR = Path(__file__).resolve().parent / ".venv" / "grammars"

# The tree-sitter grammars we use: one for each language whose symbols and imports we read.
GRAMMARS = ["php", "javascript", "typescript", "tsx", "python", "go", "java", "bash", "rust"]

# Limits against hostile repositories (the caller overrides them from config/gitchronicle.php).
LIMITS = {
    "max_symbols_per_file": 2000,
    "max_imports_per_file": 500,
    "max_symbols_total": 60000,
    "seconds_per_file": 10.0,
    "seconds_total": 150.0,
    "max_file_bytes": 1024 * 1024,
    "max_config_bytes": 256 * 1024,
}

# File extension -> (language name shown in the app, grammar). Without a grammar we count lines only.
LANGUAGES = {
    ".php": ("PHP", "php"),
    ".js": ("JavaScript", "javascript"),
    ".jsx": ("JavaScript", "javascript"),
    ".mjs": ("JavaScript", "javascript"),
    ".cjs": ("JavaScript", "javascript"),
    ".ts": ("TypeScript", "typescript"),
    ".mts": ("TypeScript", "typescript"),
    ".cts": ("TypeScript", "typescript"),
    ".tsx": ("TypeScript", "tsx"),
    ".py": ("Python", "python"),
    ".go": ("Go", "go"),
    ".java": ("Java", "java"),
    ".css": ("CSS", None),
    ".scss": ("SCSS", None),
    ".less": ("Less", None),
    ".html": ("HTML", None),
    ".vue": ("Vue", None),
    ".svelte": ("Svelte", None),
    ".md": ("Markdown", None),
    ".mdx": ("MDX", None),
    ".json": ("JSON", None),
    ".jsonc": ("JSON", None),
    ".yml": ("YAML", None),
    ".yaml": ("YAML", None),
    ".xml": ("XML", None),
    ".svg": ("SVG", None),
    ".toml": ("TOML", None),
    ".sql": ("SQL", None),
    ".sh": ("Shell", "bash"),
    ".bash": ("Shell", "bash"),
    ".zsh": ("Shell", "bash"),
    ".rb": ("Ruby", None),
    ".rs": ("Rust", "rust"),
    ".c": ("C", None),
    ".h": ("C", None),
    ".cpp": ("C++", None),
    ".cs": ("C#", None),
    ".kt": ("Kotlin", None),
    ".swift": ("Swift", None),
}

# Files recognized by their whole name, not by their extension.
LANGUAGES_BY_NAME = {
    "Dockerfile": ("Dockerfile", None),
    "Containerfile": ("Dockerfile", None),
    "Makefile": ("Makefile", None),
}

# The interpreter named in a shebang line -> (language, grammar), for files without a known extension.
# Versions are cut before the lookup: python3.12 -> python.
SHEBANG_LANGUAGES = {
    "sh": ("Shell", "bash"),
    "bash": ("Shell", "bash"),
    "zsh": ("Shell", "bash"),
    "dash": ("Shell", "bash"),
    "ksh": ("Shell", "bash"),
    "python": ("Python", "python"),
    "node": ("JavaScript", "javascript"),
    "nodejs": ("JavaScript", "javascript"),
    "ruby": ("Ruby", None),
    "perl": ("Perl", None),
    "php": ("PHP", "php"),
}

# Syntax tree node -> symbol kind, for each grammar.
SYMBOL_NODES = {
    "php": {
        "class_declaration": "class",
        "interface_declaration": "interface",
        "trait_declaration": "trait",
        "enum_declaration": "enum",
        "function_definition": "function",
        "method_declaration": "method",
    },
    "javascript": {
        "class_declaration": "class",
        "function_declaration": "function",
        "generator_function_declaration": "function",
        "method_definition": "method",
    },
    "python": {
        "class_definition": "class",
        "function_definition": "function",
    },
    "go": {
        "function_declaration": "function",
        "method_declaration": "method",
        "type_spec": "type",
    },
    "java": {
        "class_declaration": "class",
        "interface_declaration": "interface",
        "annotation_type_declaration": "interface",
        "enum_declaration": "enum",
        "record_declaration": "record",
        "method_declaration": "method",
        "constructor_declaration": "method",
    },
}
SYMBOL_NODES["bash"] = {"function_definition": "function"}
SYMBOL_NODES["rust"] = {
    "function_item": "function",
    "struct_item": "struct",
    "enum_item": "enum",
    "trait_item": "trait",
    "type_item": "type",
}
SYMBOL_NODES["typescript"] = {
    **SYMBOL_NODES["javascript"],
    "abstract_class_declaration": "class",
    "interface_declaration": "interface",
    "type_alias_declaration": "type",
    "enum_declaration": "enum",
}
SYMBOL_NODES["tsx"] = SYMBOL_NODES["typescript"]

# The extensions tried, in this order, for a JavaScript/TypeScript import without one.
JS_EXTENSIONS = [".ts", ".tsx", ".js", ".jsx", ".mjs", ".cjs", ".d.ts"]


def main() -> int:
    parser = argparse.ArgumentParser(description="Analyze the code of a repository with tree-sitter.")
    parser.add_argument("repository", nargs="?", help="the folder of the cloned repository")
    parser.add_argument("--files-from", help='file with one relative path per line ("-" = stdin)')
    parser.add_argument("--cache", help="JSON file that remembers the parsed files between runs")
    for option, default in LIMITS.items():
        parser.add_argument("--" + option.replace("_", "-"), type=type(default), default=default)
    parser.add_argument("--install-grammars", action="store_true", help="download the grammars (needs the network)")
    arguments = parser.parse_args()

    language_pack.configure(language_pack.PackConfig(cache_dir=str(GRAMMARS_DIR)))

    if arguments.install_grammars:
        language_pack.download(GRAMMARS)
        print(f"Grammars installed in {GRAMMARS_DIR}: {', '.join(GRAMMARS)}")
        return 0

    if arguments.repository is None or arguments.files_from is None:
        parser.error("give the repository folder and --files-from")

    # get_parser() downloads a missing grammar, so we check first: the analysis must stay offline.
    missing = [grammar for grammar in GRAMMARS if grammar not in language_pack.downloaded_languages()]
    if missing:
        print(f"Missing tree-sitter grammars: {', '.join(missing)}. Run: composer analyzer:install", file=sys.stderr)
        return 2

    LIMITS.update({option: getattr(arguments, option) for option in LIMITS})
    root = Path(arguments.repository).resolve()
    paths = read_file_list(arguments.files_from)
    cache_path = Path(arguments.cache) if arguments.cache else None
    result, parsed_files = analyze_repository(root, paths, read_cache(cache_path))

    if cache_path is not None:
        write_cache(cache_path, parsed_files)

    json.dump(result, sys.stdout, separators=(",", ":"))
    return 0


def read_file_list(files_from: str) -> list[str]:
    """The paths to analyze, relative to the repository, without empty lines."""
    # File names are bytes in git: a name that is not UTF-8 must not stop the analysis, so it is decoded loosely
    # (it will not match a file and ends up in "errors").
    raw = sys.stdin.buffer.read() if files_from == "-" else Path(files_from).read_bytes()
    text = raw.decode("utf-8", errors="replace")

    return [line for line in text.split("\n") if line.strip() != ""]


def analyzer_version() -> str:
    """The hash of this very file: a cache written by another version of the analyzer is not used."""
    return hashlib.sha256(Path(__file__).read_bytes()).hexdigest()


def read_cache(cache_path: Path | None) -> dict:
    """What the previous run parsed (path -> parsed file), or nothing when there is no usable cache."""
    if cache_path is None or not cache_path.is_file():
        return {}

    try:
        cache = json.loads(cache_path.read_text(encoding="utf-8"))
    except (OSError, ValueError):
        return {}

    if not isinstance(cache, dict) or cache.get("analyzer") != analyzer_version():
        return {}

    return cache.get("files", {})


def write_cache(cache_path: Path, parsed_files: dict) -> None:
    """Save what this run knows about every file. The file is written whole, then renamed over the
    old one, so a run that is stopped halfway never leaves a broken cache behind."""
    cache_path.parent.mkdir(parents=True, exist_ok=True)
    temporary_path = cache_path.with_name(cache_path.name + ".tmp")
    temporary_path.write_text(
        json.dumps({"analyzer": analyzer_version(), "files": parsed_files}, separators=(",", ":")),
        encoding="utf-8",
    )
    os.replace(temporary_path, cache_path)


def analyze_repository(root: Path, paths: list[str], cache: dict) -> tuple[dict, dict]:
    """Analyze every file. Returns the result to print and what was parsed (the next run's cache)."""
    parsers = {}
    resolver = ImportResolver(root, set(paths), lambda path: read_rust_reexports(root, path, parsers))
    files = []
    errors = []
    parsed_files = {}
    parsed_count = 0
    left_out = {"files": 0, "symbols": 0, "imports": 0}
    symbols_kept = 0
    started = time.monotonic()

    for path in paths:
        # The whole run has a time budget: the files that do not fit are counted, not analyzed.
        if time.monotonic() - started > LIMITS["seconds_total"]:
            left_out["files"] += 1
            continue

        try:
            parsed, was_cached = parse_file(root, path, parsers, cache.get(path))
        except Exception as error:  # one broken file must never stop the analysis
            reason = error.strerror if isinstance(error, OSError) and error.strerror else str(error)
            errors.append({"path": path, "message": f"{type(error).__name__}: {reason}"})
            continue

        parsed_files[path] = parsed
        parsed_count += 0 if was_cached else 1

        # The symbols of the whole repository have a budget too (a file may already have been cut to its own limit).
        symbols = parsed["symbols"][: max(0, LIMITS["max_symbols_total"] - symbols_kept)]
        symbols_kept += len(symbols)
        left_out["symbols"] += parsed.get("symbols_dropped", 0) + len(parsed["symbols"]) - len(symbols)
        left_out["imports"] += parsed.get("imports_dropped", 0)

        # The imports are resolved at every run: an import may point to a file added or deleted since.
        resolved_imports = [
            {"raw": raw, **resolver.resolve(parsed["grammar"], path, raw, names)}
            for raw, names in parsed["imports"]
        ]

        files.append({
            "path": path,
            "language": parsed["language"],
            "lines": parsed["lines"],
            "cached": was_cached,
            "symbols": symbols,
            # A file never imports itself (`use crate::Item` in lib.rs, `use self::x` ...): nothing to show.
            "imports": [item for item in resolved_imports if item["resolved_path"] != path],
        })

    return {"files": files, "errors": errors, "parsed": parsed_count, "left_out": left_out}, parsed_files


def parse_file(root: Path, path: str, parsers: dict, remembered: dict | None) -> tuple[dict, bool]:
    """What one file contains: its language, lines, symbols and imports as written in the code
    (not resolved yet). When the previous run saw the same content, its answer is reused."""
    source = read_clone_file(root, path, LIMITS["max_file_bytes"])
    content_hash = hashlib.sha1(source).hexdigest()

    if remembered is not None and remembered.get("hash") == content_hash:
        return remembered, True

    language, grammar = detect_language(path, source)
    parsed = {"hash": content_hash, "language": language, "grammar": grammar, "lines": count_lines(source), "symbols": [], "imports": []}

    if grammar is None:
        return parsed, False

    if grammar not in parsers:
        parsers[grammar] = language_pack.get_parser(grammar)

    tree = parse_with_time_limit(parsers[grammar], source)
    symbols = find_symbols(tree.root_node, grammar)
    imports = [[raw, names] for raw, names in unique_imports(find_imports(tree.root_node, grammar))]

    # A file with more symbols or imports than the limits is cut; the numbers left out are remembered and reported.
    parsed["symbols"] = symbols[: LIMITS["max_symbols_per_file"]]
    parsed["imports"] = imports[: LIMITS["max_imports_per_file"]]
    parsed["symbols_dropped"] = len(symbols) - len(parsed["symbols"])
    parsed["imports_dropped"] = len(imports) - len(parsed["imports"])

    return parsed, False


def read_clone_file(root: Path, relative: str, limit: int) -> bytes:
    """The bytes of a file of the clone, or an error. The clone is written by a stranger, so a symbolic link
    (in the file name or in a folder on the way) is never followed, the path may not leave the clone, and a file
    over `limit` bytes is not read (only its size is asked)."""
    absolute = root / relative

    if absolute.is_symlink() or not absolute.resolve().is_relative_to(root):
        raise ValueError("the path leaves the repository")

    if not absolute.is_file():
        raise ValueError("not a regular file")

    if absolute.stat().st_size > limit:
        raise ValueError(f"the file is larger than {limit} bytes")

    with absolute.open("rb") as handle:
        return handle.read(limit + 1)[:limit]


def parse_with_time_limit(parser, source: bytes):
    """Parse the source; stop after LIMITS["seconds_per_file"] seconds (tree-sitter calls the progress callback
    while it works, and a callback that returns True cancels the parse)."""
    deadline = time.monotonic() + LIMITS["seconds_per_file"]

    try:
        return parser.parse(lambda offset, point: source[offset:offset + 65536], progress_callback=lambda *_: time.monotonic() > deadline)
    except ValueError as error:
        raise TimeoutError("parsing took too long") from error


def detect_language(path: str, source: bytes = b"") -> tuple[str | None, str | None]:
    """The language by the file name; a file with no known extension gets it from its shebang line."""
    name = posixpath.basename(path)

    if name.endswith(".blade.php"):
        return "Blade", None
    if name in LANGUAGES_BY_NAME:
        return LANGUAGES_BY_NAME[name]

    known = LANGUAGES.get(posixpath.splitext(name)[1].lower())

    return known if known is not None else shebang_language(source)


def shebang_language(source: bytes) -> tuple[str | None, str | None]:
    """`#!/bin/bash`, `#!/usr/bin/env python3` or `#!/usr/bin/env -S node --flag` -> the language of the
    interpreter. The first line only; anything that is not a shebang of a known interpreter gives None."""
    if not source.startswith(b"#!"):
        return None, None

    # Only the start of the line counts: a "shebang" that is megabytes long is not one.
    words = source[2:512].split(b"\n", 1)[0].decode("utf-8", errors="replace").split()
    if not words:
        return None, None

    interpreter = posixpath.basename(words[0])
    if interpreter == "env":  # skip the options (-S, -i) and the NAME=value pairs of env
        rest = [word for word in words[1:] if not word.startswith("-") and "=" not in word]
        interpreter = posixpath.basename(rest[0]) if rest else ""

    return SHEBANG_LANGUAGES.get(interpreter.rstrip("0123456789."), (None, None))


def count_lines(source: bytes) -> int:
    """Like `wc -l`, plus the last line when it does not end with a newline."""
    if source == b"":
        return 0

    return source.count(b"\n") + (0 if source.endswith(b"\n") else 1)


def walk(node):
    """Every node of the syntax tree, parents before children, in the order of the source."""
    stack = [node]
    while stack:
        current = stack.pop()
        yield current
        stack.extend(reversed(current.children))


def text(node) -> str:
    return node.text.decode("utf-8", errors="replace")


def find_symbols(root, grammar: str) -> list[dict]:
    symbols = []

    for node in walk(root):
        kind = SYMBOL_NODES[grammar].get(node.type)

        if kind is None and grammar in ("javascript", "typescript", "tsx") and is_top_level_function_variable(node):
            kind = "function"

        name_node = node.child_by_field_name("name") if kind is not None else None
        if name_node is None:
            continue

        if grammar == "python" and kind == "function" and is_inside_python_class(node):
            kind = "method"
        if grammar == "rust" and kind == "function" and is_inside_rust_impl(node):
            kind = "method"
        if grammar == "go" and kind == "type":
            type_node = node.child_by_field_name("type")
            kind = {"struct_type": "struct", "interface_type": "interface"}.get(type_node.type if type_node else "", "type")

        symbols.append({
            "kind": kind,
            "name": text(name_node),
            "start_line": node.start_point.row + 1,
            "end_line": node.end_point.row + 1,
        })

    return symbols


def is_top_level_function_variable(node) -> bool:
    """`const handle = () => {}` or `export const f = function () {}` at the top of the file."""
    if node.type != "variable_declarator":
        return False

    value = node.child_by_field_name("value")
    declaration = node.parent
    owner = declaration.parent if declaration is not None else None

    return (
        value is not None
        and value.type in ("arrow_function", "function_expression")
        and owner is not None
        and owner.type in ("program", "export_statement")
    )


def is_inside_python_class(node) -> bool:
    parent = node.parent
    if parent is not None and parent.type == "decorated_definition":
        parent = parent.parent

    return parent is not None and parent.type == "block" and parent.parent.type == "class_definition"


def is_inside_rust_impl(node) -> bool:
    """A `fn` in the body of an `impl` or a `trait` is a method."""
    body = node.parent
    return body is not None and body.type == "declaration_list" and body.parent is not None and body.parent.type in ("impl_item", "trait_item")


def find_imports(root, grammar: str) -> list[tuple[str, list[str]]]:
    """The imports of a file as (raw import, imported names); the names matter only for Python."""
    if grammar == "php":
        return php_imports(root)
    if grammar in ("javascript", "typescript", "tsx"):
        return js_imports(root)
    if grammar == "python":
        return python_imports(root)
    if grammar == "go":
        return [(text(node.child_by_field_name("path")).strip('"`'), []) for node in walk(root) if node.type == "import_spec"]
    if grammar == "java":
        return java_imports(root)
    if grammar == "bash":
        return shell_imports(root)
    if grammar == "rust":
        return rust_imports(root)

    return []


def php_imports(root) -> list[tuple[str, list[str]]]:
    """`use App\\Models\\User;` and `use App\\Models\\{User, Post};` as full class names."""
    imports = []

    for declaration in walk(root):
        if declaration.type != "namespace_use_declaration":
            continue

        group_prefix = next((text(child) for child in declaration.children if child.type == "namespace_name"), "")

        for clause in walk(declaration):
            if clause.type != "namespace_use_clause":
                continue
            name = next((text(child) for child in clause.children if child.type in ("qualified_name", "name")), None)
            if name is None:
                continue
            full_name = f"{group_prefix}\\{name}" if group_prefix else name
            imports.append((full_name.lstrip("\\"), []))

    return imports


def js_imports(root) -> list[tuple[str, list[str]]]:
    """`import ... from 'x'`, `export ... from 'x'`, `require('x')` and `import('x')`."""
    imports = []

    for node in walk(root):
        source = None

        if node.type in ("import_statement", "export_statement"):
            source = node.child_by_field_name("source")
        elif node.type == "call_expression":
            function = node.child_by_field_name("function")
            arguments = node.child_by_field_name("arguments")
            is_require = function is not None and (function.type == "import" or text(function) == "require")
            if is_require and arguments is not None and arguments.named_child_count > 0:
                source = arguments.named_children[0]

        if source is not None and source.type == "string":
            imports.append((text(source)[1:-1], []))

    return imports


def python_imports(root) -> list[tuple[str, list[str]]]:
    """`import a.b` and `from .a import b` as module names; for `from . import x` the module is `.x`."""
    imports = []

    for node in walk(root):
        if node.type == "import_statement":
            for name in node.children_by_field_name("name"):
                module = name.child_by_field_name("name") if name.type == "aliased_import" else name
                imports.append((text(module), []))
        elif node.type == "import_from_statement":
            module = text(node.child_by_field_name("module_name"))
            names = []
            for name in node.children_by_field_name("name"):
                name = name.child_by_field_name("name") if name.type == "aliased_import" else name
                names.append(text(name))
            if module.strip(".") == "":
                imports.extend((module + name, []) for name in names)
            else:
                imports.append((module, names))

    return imports


def java_imports(root) -> list[tuple[str, list[str]]]:
    """`import com.acme.Foo;`, `import static com.acme.Foo.bar;` and `import com.acme.*;`."""
    imports = []

    for node in walk(root):
        if node.type != "import_declaration":
            continue
        name = next((text(child) for child in node.children if child.type in ("scoped_identifier", "identifier")), None)
        if name is None:
            continue
        wildcard = any(child.type == "asterisk" for child in node.children)
        imports.append((name + ".*" if wildcard else name, []))

    return imports


def shell_imports(root) -> list[tuple[str, list[str]]]:
    """The scripts a shell script loads or runs, as written: `source x`, `. x`, `./x.sh`, `bash x.sh`,
    `exec ./x.sh`. Only the first argument is read, and only when it is a plain word or a string."""
    own_dir_variables = shell_own_directory_variables(root)
    imports = []

    for node in walk(root):
        if node.type != "command":
            continue

        name_node = node.child_by_field_name("name")
        arguments = [child for child in node.children_by_field_name("argument") if child.type in SHELL_ARGUMENT_NODES]
        if name_node is None:
            continue
        command = text(name_node).lstrip("\\")  # `\.` and `\source` skip aliases: same command

        if command in ("source", "."):
            if arguments:
                imports.append(shell_import(shell_word(arguments[0]), own_dir_variables))
            continue

        target = None
        if command in SHELL_INTERPRETERS or command == "exec":
            words = [shell_word(argument) for argument in arguments]
            if command == "exec" and words and posixpath.basename(words[0]) in SHELL_INTERPRETERS:
                words = words[1:]
            target = None if "-c" in words else next((word for word in words if not word.startswith("-")), None)
        elif "/" in command:
            target = shell_word(name_node).lstrip("\\")

        if target is not None and looks_like_script_path(target, own_dir_variables):
            imports.append(shell_import(target, own_dir_variables))

    return imports


def shell_import(path: str, own_dir_variables: set[str]) -> tuple[str, list[str]]:
    """The import as written, plus (in the names) the part after the script's own folder when the path
    starts with that idiom, so the resolver can look for it next to the importing script."""
    rest = strip_own_directory(path, own_dir_variables)

    return path, ([] if rest is None else [SCRIPT_DIRECTORY_MARK + rest])


# The commands that run a script given as their first argument, and the node types of an argument we read.
SHELL_INTERPRETERS = ("sh", "bash", "zsh", "dash", "ksh")
SHELL_ARGUMENT_NODES = ("word", "string", "raw_string", "concatenation", "simple_expansion", "expansion")
SCRIPT_SUFFIXES = (".sh", ".bash", ".zsh")
SCRIPT_DIRECTORY_MARK = "@script-dir:"


def shell_word(node) -> str:
    """The text of an argument without its quotes."""
    value = text(node)
    if len(value) >= 2 and value[0] == value[-1] and value[0] in "'\"":
        value = value[1:-1]

    return value


def looks_like_script_path(path: str, own_dir_variables: set[str]) -> bool:
    """A command we count as 'runs a script of the repository': a relative path that goes through a folder
    (./x, a/b, $DIR/x) and ends in a script suffix, or that can be found in the repository at all."""
    return path.endswith(SCRIPT_SUFFIXES) or path.startswith(("./", "../")) or strip_own_directory(path, own_dir_variables) is not None


def shell_own_directory_variables(root) -> set[str]:
    """The variables that hold the folder of the script itself: DIR=$(dirname "$0"),
    SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)", ..."""
    variables = set()

    for node in walk(root):
        if node.type == "variable_assignment":
            name = node.child_by_field_name("name")
            value = node.child_by_field_name("value")
            if name is not None and value is not None and is_own_directory_expression(text(value)):
                variables.add(text(name))

    return variables


def is_own_directory_expression(expression: str) -> bool:
    """`$(dirname "$0")`, `$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)`, `${0%/*}`: the folder of this script."""
    if re.search(r"\$\{(0|BASH_SOURCE(\[0\])?)%/\*\}", expression):
        return True

    return "dirname" in expression and re.search(r"\$0|\$\{0\}|BASH_SOURCE", expression) is not None


def strip_own_directory(path: str, own_dir_variables: set[str]) -> str | None:
    """`$DIR/x.sh`, `${DIR}/x.sh`, `$(dirname "$0")/x.sh`, `${0%/*}/x.sh` -> "x.sh" (the part after the
    folder of the script itself); None when the path does not start that way."""
    match = re.match(r"\$\{?(\w+)\}?/(.*)\Z", path, re.DOTALL)
    if match and match.group(1) in own_dir_variables:
        return match.group(2)

    match = re.match(r"\$\{(0|BASH_SOURCE(\[0\])?)%/\*\}/(.*)\Z", path, re.DOTALL)
    if match:
        return match.group(3)

    if path.startswith("$("):
        depth = 0
        for index in range(1, len(path)):  # the first "(" is at 1; stop at its matching ")"
            depth += (path[index] == "(") - (path[index] == ")")
            if depth == 0:
                rest = path[index + 1:]
                if is_own_directory_expression(path[:index + 1]) and rest.startswith("/"):
                    return rest[1:]
                break

    return None


def rust_imports(root) -> list[tuple[str, list[str]]]:
    """`mod name;` as "mod name", and every `use` path flattened into full paths: `use a::{b, c::d as e}` gives
    "a::b" and "a::c::d"; `use a::b::*` gives "a::b". A `use super::` inside an inline `mod x { }` really
    points to the file itself, so it is left out; so is `use self::` there."""
    imports = []

    for node in walk(root):
        if node.type == "mod_item" and node.child_by_field_name("body") is None:
            name = node.child_by_field_name("name")
            if name is not None:
                imports.append(("mod " + text(name), []))
        elif node.type == "use_declaration":
            argument = node.child_by_field_name("argument")
            inline_depth = sum(1 for ancestor in ancestors(node) if ancestor.type == "mod_item")
            for path in rust_use_paths(argument, []) if argument is not None else []:
                path = rust_path_in_inline_module(path, inline_depth)
                if path:
                    imports.append(("::".join(path), []))

    return imports


def ancestors(node):
    parent = node.parent
    while parent is not None:
        yield parent
        parent = parent.parent


def rust_path_in_inline_module(path: list[str], depth: int) -> list[str]:
    """The path of a `use` seen from the file, when the `use` sits in `depth` nested inline modules.
    Returns [] when it points into the inline module itself or to the file (nothing to import)."""
    if depth == 0:
        return path

    if path[0] == "self":
        return []
    supers = 0
    while supers < len(path) and path[supers] == "super":
        supers += 1
    if supers == 0:
        return path  # `use std::io;` or `use crate::x;` means the same everywhere
    if supers <= depth:
        return []  # super of an inline module: the file itself or an inline module inside it

    return ["super"] * (supers - depth) + path[supers:]


def rust_use_paths(node, prefix: list[str]) -> list[list[str]]:
    """The full paths named by one `use` argument (a tree of paths, groups, renames and globs)."""
    kind = node.type

    if kind in ("identifier", "crate", "self", "super", "metavariable"):
        # `self` inside a group (`use a::{self, b}`) names the group's own prefix.
        return [prefix] if kind == "self" and prefix else [prefix + [text(node)]]
    if kind == "scoped_identifier":
        path_node = node.child_by_field_name("path")
        name_node = node.child_by_field_name("name")
        head = rust_use_paths(path_node, prefix)[0] if path_node is not None else prefix
        return [head + [text(name_node)]] if name_node is not None else [head]
    if kind == "use_as_clause":
        return rust_use_paths(node.child_by_field_name("path"), prefix)
    if kind == "use_wildcard":
        inner = next((child for child in node.children if child.type not in ("::", "*")), None)
        return rust_use_paths(inner, prefix) if inner is not None else ([prefix] if prefix else [])
    if kind == "scoped_use_list":
        path_node = node.child_by_field_name("path")
        head = rust_use_paths(path_node, prefix)[0] if path_node is not None else prefix
        return rust_use_paths(node.child_by_field_name("list"), head)
    if kind == "use_list":
        return [path for child in node.named_children for path in rust_use_paths(child, prefix)]

    return []


def rust_reexport_entries(node, prefix: list[str]) -> list[tuple[list[str], str | None, bool]]:
    """What one `use` argument exports, as (full path, name it is visible under, is a glob):
    `a::b` is (["a", "b"], "b", False), `a::b as c` is (["a", "b"], "c", False), `a::*` is (["a"], None, True)."""
    kind = node.type

    if kind in ("identifier", "crate", "self", "super"):
        if kind == "self" and prefix:
            return [(prefix, prefix[-1], False)]
        return [(prefix + [text(node)], text(node), False)]
    if kind == "scoped_identifier":
        entries = rust_use_paths(node, prefix)
        return [(path, path[-1], False) for path in entries if path]
    if kind == "use_as_clause":
        alias = node.child_by_field_name("alias")
        return [(path, text(alias) if alias is not None else path[-1], False) for path in rust_use_paths(node.child_by_field_name("path"), prefix) if path]
    if kind == "use_wildcard":
        return [(path, None, True) for path in rust_use_paths(node, prefix) if path]
    if kind == "scoped_use_list":
        path_node = node.child_by_field_name("path")
        head = rust_use_paths(path_node, prefix)[0] if path_node is not None else prefix
        return rust_reexport_entries(node.child_by_field_name("list"), head)
    if kind == "use_list":
        return [entry for child in node.named_children for entry in rust_reexport_entries(child, prefix)]

    return []


def read_rust_reexports(root: Path, path: str, parsers: dict) -> list[tuple[list[str], str | None, bool]]:
    """The `pub use` declarations of one Rust file, read when an import has to pass through the file.
    Anything that cannot be read or parsed simply has no re-exports."""
    try:
        source = read_clone_file(root, path, LIMITS["max_file_bytes"])
        if "rust" not in parsers:
            parsers["rust"] = language_pack.get_parser("rust")
        tree = parse_with_time_limit(parsers["rust"], source)
    except Exception:
        return []

    entries = []
    for node in walk(tree.root_node):
        if node.type != "use_declaration" or not any(child.type == "visibility_modifier" for child in node.children):
            continue
        argument = node.child_by_field_name("argument")
        if argument is not None:
            entries.extend(rust_reexport_entries(argument, []))

    return entries


def unique_imports(imports: list[tuple[str, list[str]]]) -> list[tuple[str, list[str]]]:
    """The same import written twice in a file (for example `import type` and `import`) counts once."""
    seen = {}
    for raw, names in imports:
        seen.setdefault(raw, names)

    return list(seen.items())


# How many `pub use` re-exports one import may pass through (a cycle of re-exports stops here).
RUST_REEXPORT_DEPTH = 8

class ImportResolver:
    """Finds the repository file an import points to, using each language's own rules:

    - PHP: the PSR-4 map of composer.json (App\\ -> app/);
    - JavaScript/TypeScript: relative paths, with or without extension or index file,
      and the aliases from tsconfig.json/jsconfig.json ("@/*" -> "resources/js/*");
    - Python: relative modules (.a.b) and modules inside the repository;
    - Go: the packages under the module path of go.mod (a package is a folder: we take its first file);
    - Java: the package as a folder (com.acme.Foo -> .../com/acme/Foo.java);
    - Shell: a literal relative path (next to the script, or from the repository root) or a path after the
      script's own folder (`$DIR/x.sh`); a path built from another variable is never guessed (external);
    - Rust: `mod x;` (x.rs or x/mod.rs), `crate::`, `self::`, `super::` paths through the module files of the
      crate, and the crates of the repository (workspace members and `path =` dependencies) by name; a path
      that goes through a `pub use` re-export (named, renamed, grouped or glob) ends in the file that defines it.

    "external" is true for an import that clearly comes from outside the repository (a library).
    """

    def __init__(self, root: Path, paths: set[str], rust_reexports=None):
        self.paths = paths
        self.rust_reexports = rust_reexports or (lambda path: [])
        self.rust_reexport_cache = {}
        self.rust_reexport_depth = 0  # how many re-exports the resolution in progress has already followed
        self.psr4 = self.read_psr4(root)
        self.js_aliases = self.read_js_aliases(root)
        self.go_module = self.read_go_module(root)
        self.rust_crates, self.rust_roots = self.read_rust_crates(root, paths)
        self.files_by_name = {}
        for path in sorted(paths):
            self.files_by_name.setdefault(posixpath.basename(path), []).append(path)

    def resolve(self, grammar: str, importer: str, raw: str, names: list[str]) -> dict:
        if grammar == "php":
            resolved, external = self.resolve_php(raw)
        elif grammar in ("javascript", "typescript", "tsx"):
            resolved, external = self.resolve_js(importer, raw)
        elif grammar == "python":
            resolved, external = self.resolve_python(importer, raw, names)
        elif grammar == "go":
            resolved, external = self.resolve_go(raw)
        elif grammar == "bash":
            resolved, external = self.resolve_shell(importer, raw, names)
        elif grammar == "rust":
            resolved, external = self.resolve_rust(importer, raw)
        else:
            resolved, external = self.resolve_java(raw)

        return {"resolved_path": resolved, "external": external}

    def first_existing(self, candidates) -> str | None:
        return next((candidate for candidate in candidates if candidate in self.paths), None)

    def resolve_php(self, class_name: str) -> tuple[str | None, bool]:
        for prefix, folders in self.psr4:
            if class_name.startswith(prefix):
                relative = class_name[len(prefix):].replace("\\", "/") + ".php"
                return self.first_existing(normalize(posixpath.join(folder, relative)) for folder in folders), False

        return None, True

    def resolve_js(self, importer: str, specifier: str) -> tuple[str | None, bool]:
        if specifier.startswith("."):
            bases = [normalize(posixpath.join(posixpath.dirname(importer), specifier))]
        else:
            bases = [
                normalize(target + specifier[len(alias):])
                for alias, targets in self.js_aliases
                if specifier.startswith(alias)
                for target in targets
            ]
            if not bases:
                return None, True

        for base in bases:
            stem, extension = posixpath.splitext(base)
            candidates = [base]
            candidates += [base + suffix for suffix in JS_EXTENSIONS]
            candidates += [f"{base}/index{suffix}" for suffix in JS_EXTENSIONS]
            if extension in (".js", ".jsx", ".mjs", ".cjs"):  # TypeScript code may import "./x.js" for x.ts
                candidates += [stem + ".ts", stem + ".tsx"]
            resolved = self.first_existing(candidates)
            if resolved is not None:
                return resolved, False

        return None, False

    def resolve_python(self, importer: str, module: str, names: list[str]) -> tuple[str | None, bool]:
        dots = len(module) - len(module.lstrip("."))
        module_path = module[dots:].replace(".", "/")

        if dots > 0:
            folder = posixpath.dirname(importer)
            for _ in range(dots - 1):
                folder = posixpath.dirname(folder)
            roots, external = [folder], False
        else:
            roots, external = [posixpath.dirname(importer), "", "src"], True

        for root in roots:
            base = normalize(posixpath.join(root, module_path)) if module_path else normalize(root)
            candidates = [base + ".py", base + "/__init__.py"]
            candidates += [f"{base}/{name}.py" for name in names]  # `from package import module`
            resolved = self.first_existing(candidates)
            if resolved is not None:
                return resolved, False

        return None, external

    def resolve_go(self, package: str) -> tuple[str | None, bool]:
        if self.go_module is None or not (package == self.go_module or package.startswith(self.go_module + "/")):
            return None, True

        folder = package[len(self.go_module):].lstrip("/")
        files = sorted(
            path for path in self.paths
            if posixpath.dirname(path) == folder and path.endswith(".go") and not path.endswith("_test.go")
        )

        return (files[0] if files else None), False

    def resolve_java(self, name: str) -> tuple[str | None, bool]:
        if name.endswith(".*"):
            folder = name[:-2].replace(".", "/")
            files = sorted(
                path for path in self.paths
                if path.endswith(".java") and (posixpath.dirname(path) == folder or posixpath.dirname(path).endswith("/" + folder))
            )
            return (files[0], False) if files else (None, True)

        parts = name.split(".")
        for length in (len(parts), len(parts) - 1):  # the class itself, or its class for `import static`
            relative = "/".join(parts[:length]) + ".java"
            for path in self.files_by_name.get(posixpath.basename(relative), []):
                if path == relative or path.endswith("/" + relative):
                    return path, False

        return None, True

    def resolve_shell(self, importer: str, path: str, names: list[str]) -> tuple[str | None, bool]:
        folder = posixpath.dirname(importer)
        script_relative = next((name[len(SCRIPT_DIRECTORY_MARK):] for name in names if name.startswith(SCRIPT_DIRECTORY_MARK)), None)

        if script_relative is not None:
            candidates = [normalize(posixpath.join(folder, script_relative))]
        elif path.startswith(("/", "~")) or re.search(r"[$`*?{}\s]", path):
            return None, True  # outside the repository, or built from a variable: not guessed
        else:
            candidates = [normalize(posixpath.join(folder, path)), normalize(path)]  # next to the script, then from the root

        return self.first_existing(candidates), False

    def resolve_rust(self, importer: str, raw: str) -> tuple[str | None, bool]:
        base, root = self.rust_base_and_root(importer)

        if raw.startswith("mod "):
            folder = self.rust_child_folder(importer)
            name = raw[4:]
            return self.first_existing([normalize(posixpath.join(folder, name + ".rs")), normalize(posixpath.join(folder, name, "mod.rs"))]), False

        first, *rest = raw.split("::")
        folder = None

        if first == "crate":
            start, folder = root, base
        elif first == "self":
            start, folder = importer, self.rust_child_folder(importer)
        elif first == "super":
            start, rest = importer, [first, *rest]
            while rest and rest[0] == "super" and start is not None:
                start, rest = self.rust_parent_module(start), rest[1:]
            folder = self.rust_child_folder(start) if start is not None else None
        elif first in self.rust_crates:
            start = self.rust_crates[first]
            folder = posixpath.dirname(start) if start is not None else None
        else:
            # A module declared in the importing file (`mod a;` then `use a::b;`), otherwise a library.
            found = self.walk_rust_modules(importer, self.rust_child_folder(importer), [first, *rest])
            return (found, False) if found != importer else (None, True)

        return (self.walk_rust_modules(start, folder, rest) if start is not None and folder is not None else None), False

    def walk_rust_modules(self, start: str, folder: str, segments: list[str]) -> str:
        """Follow the path through module files from `start` (and through `pub use` re-exports); the walk stops at
        the first name that is neither a module nor a re-export (an item of the module reached so far) and returns
        the last module reached."""
        current = start

        for segment in segments:
            found = self.find_rust_child(current, segment, self.rust_reexport_depth)
            if found is None:
                break
            current = found

        return current

    def find_rust_child(self, module: str, segment: str, depth: int) -> str | None:
        """The file a name inside `module` stands for: a module file next to it, otherwise the target of a named
        re-export of that name, otherwise what a glob re-export (`pub use a::*;`) brings in. `depth` counts the
        re-exports followed, so a cycle of re-exports ends instead of looping."""
        folder = self.rust_child_folder(module)
        found = self.first_existing([normalize(posixpath.join(folder, segment + ".rs")), normalize(posixpath.join(folder, segment, "mod.rs"))])
        if found is not None:
            return found
        if depth >= RUST_REEXPORT_DEPTH:
            return None

        if module not in self.rust_reexport_cache:
            self.rust_reexport_cache[module] = self.rust_reexports(module)
        entries = self.rust_reexport_cache[module]

        for path, name, is_glob in entries:
            if not is_glob and name == segment:
                target = self.rust_reexport_target(module, path, depth)
                if target is not None and target != module:
                    return target

        for path, name, is_glob in entries:
            if is_glob:
                target = self.rust_reexport_target(module, path, depth)
                if target is not None and target != module:
                    inner = self.find_rust_child(target, segment, depth + 1)
                    if inner is not None:
                        return inner

        return None

    def rust_reexport_target(self, module: str, path: list[str], depth: int) -> str | None:
        """The file a re-exported path leads to, read from the file that re-exports it."""
        self.rust_reexport_depth = depth + 1
        try:
            resolved, external = self.resolve_rust(module, "::".join(path))
        finally:
            self.rust_reexport_depth = depth

        return None if external else resolved

    def rust_child_folder(self, module: str) -> str:
        """The folder where the `mod x;` declared in this file are: next to a mod.rs, lib.rs, main.rs or
        any other crate root, inside a folder named like the file for the others (a.rs -> a/)."""
        if module in self.rust_roots or posixpath.basename(module) == "mod.rs":
            return posixpath.dirname(module)

        return posixpath.splitext(module)[0]

    def rust_parent_module(self, module: str) -> str | None:
        """The file that declares this module (`mod x;`), or None for a crate root."""
        if module in self.rust_roots:
            return None

        base, root = self.rust_base_and_root(module)
        folder = posixpath.dirname(module) if posixpath.basename(module) != "mod.rs" else posixpath.dirname(posixpath.dirname(module))

        if folder == base:
            return root

        return self.first_existing([normalize(posixpath.join(posixpath.dirname(folder), posixpath.basename(folder) + ".rs")), normalize(posixpath.join(folder, "mod.rs"))])

    def rust_base_and_root(self, file: str) -> tuple[str, str | None]:
        """The folder and the file of the crate root the given file belongs to: the root whose folder is the
        closest parent of the file (a library root before a program root in the same folder)."""
        if file in self.rust_roots:
            return posixpath.dirname(file), file

        covering = [root for root in self.rust_roots if posixpath.dirname(root) == "" or file.startswith(posixpath.dirname(root) + "/")]
        if covering:
            best = max(covering, key=lambda root: (len(posixpath.dirname(root)), root.endswith("lib.rs"), root))
            return posixpath.dirname(best), best

        folders = posixpath.dirname(file).split("/")
        base = "/".join(folders[: folders.index("src") + 1]) if "src" in folders else posixpath.dirname(file)

        return base, None

    @staticmethod
    def read_rust_crates(root: Path, paths: set[str]) -> tuple[dict[str, str | None], set[str]]:
        """The crates of the repository, from its Cargo.toml files: (library name -> library root file or None,
        every crate root file). A root is lib.rs, main.rs, a file of src/bin, tests, examples, benches,
        build.rs or a path named in Cargo.toml. Dependencies with `path =` can be renamed (`package =`)."""
        crates = {}
        roots = set()
        aliases = []

        for manifest in sorted(path for path in paths if posixpath.basename(path) == "Cargo.toml"):
            try:
                data = tomllib.loads(read_clone_file(root, manifest, LIMITS["max_config_bytes"]).decode("utf-8", errors="replace"))
            except (OSError, ValueError):
                continue

            folder = posixpath.dirname(manifest)
            def at(relative: str) -> str:
                return normalize(posixpath.join(folder, relative))

            library = data.get("lib") if isinstance(data.get("lib"), dict) else {}
            lib_root = at(library["path"]) if isinstance(library.get("path"), str) else at("src/lib.rs")
            lib_root = lib_root if lib_root in paths else None

            declared = [at(target["path"]) for key in ("bin", "example", "test", "bench") for target in (data.get(key) or []) if isinstance(target, dict) and isinstance(target.get("path"), str)]
            conventional = [path for path in paths if is_conventional_rust_root(path, at)]
            roots.update(path for path in [lib_root, *declared, *conventional] if path in paths)

            package = data.get("package")
            name = library.get("name") if isinstance(library.get("name"), str) else (package.get("name") if isinstance(package, dict) else None)
            if isinstance(name, str) and lib_root is not None:
                crates[name.replace("-", "_")] = lib_root

            tables = [data.get(key) for key in ("dependencies", "dev-dependencies", "build-dependencies")]
            workspace = data.get("workspace")
            tables.append(workspace.get("dependencies") if isinstance(workspace, dict) else None)
            for table in tables:
                for key, spec in (table.items() if isinstance(table, dict) else []):
                    if isinstance(spec, dict) and isinstance(spec.get("path"), str):
                        aliases.append((key.replace("-", "_"), at(spec["path"]), spec.get("package")))

        # A `path =` dependency is the crate in that folder, whatever name the dependency is given.
        for alias, dependency_folder, package_name in aliases:
            target = next((lib for lib in crates.values() if posixpath.dirname(posixpath.dirname(lib)) == dependency_folder or posixpath.dirname(lib) == dependency_folder), None)
            if target is not None:
                crates.setdefault(alias, target)

        return crates, roots

    @staticmethod
    def read_psr4(root: Path) -> list[tuple[str, list[str]]]:
        composer = read_json(root / "composer.json")
        mapping = []
        for section in ("autoload", "autoload-dev"):
            autoload = composer.get(section)
            psr4 = autoload.get("psr-4") if isinstance(autoload, dict) else None
            for prefix, folders in (psr4.items() if isinstance(psr4, dict) else []):
                folders = [folders] if isinstance(folders, str) else folders
                if isinstance(folders, list):
                    mapping.append((prefix, [folder for folder in folders if isinstance(folder, str)]))

        return sorted(mapping, key=lambda item: len(item[0]), reverse=True)  # the longest prefix wins

    @staticmethod
    def read_js_aliases(root: Path) -> list[tuple[str, list[str]]]:
        for name in ("tsconfig.json", "jsconfig.json"):
            options = read_json(root / name).get("compilerOptions", {})
            if not isinstance(options, dict) or not isinstance(options.get("paths"), dict):
                continue
            base = options.get("baseUrl") if isinstance(options.get("baseUrl"), str) else "."
            return [
                (alias.removesuffix("*"), [normalize(posixpath.join(base, target.removesuffix("*"))) + "/" for target in targets])
                for alias, targets in options["paths"].items()
                if alias.endswith("*") and isinstance(targets, list) and all(isinstance(target, str) for target in targets)
            ]

        return []

    @staticmethod
    def read_go_module(root: Path) -> str | None:
        try:
            content = read_clone_file(root, "go.mod", LIMITS["max_config_bytes"]).decode("utf-8", errors="replace")
        except (OSError, ValueError):
            return None
        match = re.search(r"^module\s+(\S+)", content, re.MULTILINE)

        return match.group(1) if match else None


def is_conventional_rust_root(path: str, at) -> bool:
    """Cargo's default crate roots of the package whose folder `at` resolves: src/main.rs, src/bin/x.rs,
    src/bin/x/main.rs, build.rs and the files directly in tests/, examples/ and benches/."""
    if path in (at("src/main.rs"), at("build.rs")):
        return True
    if posixpath.dirname(path) in (at("src/bin"), at("tests"), at("examples"), at("benches")):
        return path.endswith(".rs")

    return posixpath.dirname(path).startswith(at("src/bin") + "/") and posixpath.basename(path) == "main.rs" and posixpath.dirname(posixpath.dirname(path)) == at("src/bin")


def normalize(path: str) -> str:
    """A clean relative path ("a/./b/../c" -> "a/c"); a path that leaves the repository never matches a file."""
    return posixpath.normpath(path).removeprefix("./")


def read_json(path: Path) -> dict:
    """A JSON file as a dict, or {} when it is missing, too big, a link or invalid. tsconfig.json may contain comments."""
    try:
        content = read_clone_file(path.parent, path.name, LIMITS["max_config_bytes"]).decode("utf-8", errors="replace")
    except (OSError, ValueError):
        return {}

    without_trailing_commas = re.sub(r",(\s*[}\]])", r"\1", strip_json_comments(content))

    try:
        data = json.loads(without_trailing_commas)
    except ValueError:
        return {}

    return data if isinstance(data, dict) else {}


def strip_json_comments(content: str) -> str:
    """The text without `// ...` and `/* ... */` comments, in one pass over it (strings are kept as they are).
    A regular expression with a lazy `/\\*.*?\\*/` rescans the rest of the text for every `/*` that is never closed,
    so a file made of them takes time with the square of its size; here every character is looked at once."""
    pieces = []
    length = len(content)
    position = 0  # the start of the text not copied yet
    index = 0

    while index < length:
        character = content[index]

        if character == '"':  # a string: skip to its closing quote, a backslash escapes the next character
            index += 1
            while index < length and content[index] != '"':
                index += 2 if content[index] == "\\" else 1
            index += 1
        elif content.startswith("//", index):
            pieces.append(content[position:index])
            end = content.find("\n", index)
            index = position = length if end == -1 else end
        elif content.startswith("/*", index):
            pieces.append(content[position:index])
            end = content.find("*/", index + 2)
            index = position = length if end == -1 else end + 2
        else:
            index += 1

    pieces.append(content[position:])

    return "".join(pieces)


if __name__ == "__main__":
    sys.exit(main())
