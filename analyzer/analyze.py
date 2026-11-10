"""GitChronicle code analyzer: reads the files of a cloned repository with tree-sitter.

For every file it reports the language, the number of lines and, for PHP, JavaScript,
TypeScript, Python, Go and Java, the symbols (classes, functions, ...) and the imports,
each import resolved to a file of the repository when it points to one.

    python analyze.py /path/to/clone --files-from list.txt    # one path per line ("-" = stdin)
    python analyze.py --install-grammars                       # once, at install time

The result is printed as JSON on stdout:

    {"files": [{"path", "language", "lines",
                "symbols": [{"kind", "name", "start_line", "end_line"}],
                "imports": [{"raw", "resolved_path", "external"}]}],
     "errors": [{"path", "message"}]}

A file that cannot be read or parsed goes to "errors"; it never stops the others.
The analysis never uses the network: the grammars are downloaded only by --install-grammars.
"""

import argparse
import json
import posixpath
import re
import sys
from pathlib import Path

import tree_sitter_language_pack as language_pack

# The grammars live next to the virtual environment, so deleting analyzer/.venv resets everything.
GRAMMARS_DIR = Path(__file__).resolve().parent / ".venv" / "grammars"

# The tree-sitter grammars we use: one for each language whose symbols and imports we read.
GRAMMARS = ["php", "javascript", "typescript", "tsx", "python", "go", "java"]

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
    ".sh": ("Shell", None),
    ".rb": ("Ruby", None),
    ".rs": ("Rust", None),
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

    root = Path(arguments.repository).resolve()
    paths = read_file_list(arguments.files_from)
    result = analyze_repository(root, paths)

    json.dump(result, sys.stdout, separators=(",", ":"))
    return 0


def read_file_list(files_from: str) -> list[str]:
    """The paths to analyze, relative to the repository, without empty lines."""
    text = sys.stdin.read() if files_from == "-" else Path(files_from).read_text(encoding="utf-8")

    return [line for line in text.split("\n") if line.strip() != ""]


def analyze_repository(root: Path, paths: list[str]) -> dict:
    resolver = ImportResolver(root, set(paths))
    parsers = {}
    files = []
    errors = []

    for path in paths:
        try:
            files.append(analyze_file(root, path, resolver, parsers))
        except Exception as error:  # one broken file must never stop the analysis
            reason = error.strerror if isinstance(error, OSError) and error.strerror else str(error)
            errors.append({"path": path, "message": f"{type(error).__name__}: {reason}"})

    return {"files": files, "errors": errors}


def analyze_file(root: Path, path: str, resolver: "ImportResolver", parsers: dict) -> dict:
    absolute_path = root / path

    # A symbolic link could point outside the clone (for example to /etc/passwd): never follow it.
    if absolute_path.is_symlink() or not absolute_path.resolve().is_relative_to(root):
        raise ValueError("the path leaves the repository")

    source = absolute_path.read_bytes()
    language, grammar = detect_language(path)
    result = {"path": path, "language": language, "lines": count_lines(source), "symbols": [], "imports": []}

    if grammar is None:
        return result

    if grammar not in parsers:
        parsers[grammar] = language_pack.get_parser(grammar)

    tree = parsers[grammar].parse(source)
    result["symbols"] = find_symbols(tree.root_node, grammar)
    result["imports"] = [
        {"raw": raw, **resolver.resolve(grammar, path, raw, names)}
        for raw, names in unique_imports(find_imports(tree.root_node, grammar))
    ]

    return result


def detect_language(path: str) -> tuple[str | None, str | None]:
    name = posixpath.basename(path)

    if name.endswith(".blade.php"):
        return "Blade", None
    if name in LANGUAGES_BY_NAME:
        return LANGUAGES_BY_NAME[name]

    return LANGUAGES.get(posixpath.splitext(name)[1].lower(), (None, None))


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


def unique_imports(imports: list[tuple[str, list[str]]]) -> list[tuple[str, list[str]]]:
    """The same import written twice in a file (for example `import type` and `import`) counts once."""
    seen = {}
    for raw, names in imports:
        seen.setdefault(raw, names)

    return list(seen.items())


class ImportResolver:
    """Finds the repository file an import points to, using each language's own rules:

    - PHP: the PSR-4 map of composer.json (App\\ -> app/);
    - JavaScript/TypeScript: relative paths, with or without extension or index file,
      and the aliases from tsconfig.json/jsconfig.json ("@/*" -> "resources/js/*");
    - Python: relative modules (.a.b) and modules inside the repository;
    - Go: the packages under the module path of go.mod (a package is a folder: we take its first file);
    - Java: the package as a folder (com.acme.Foo -> .../com/acme/Foo.java).

    "external" is true for an import that clearly comes from outside the repository (a library).
    """

    def __init__(self, root: Path, paths: set[str]):
        self.paths = paths
        self.psr4 = self.read_psr4(root)
        self.js_aliases = self.read_js_aliases(root)
        self.go_module = self.read_go_module(root)
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
        go_mod = root / "go.mod"
        if not go_mod.is_file():
            return None
        match = re.search(r"^module\s+(\S+)", go_mod.read_text(encoding="utf-8", errors="replace"), re.MULTILINE)

        return match.group(1) if match else None


def normalize(path: str) -> str:
    """A clean relative path ("a/./b/../c" -> "a/c"); a path that leaves the repository never matches a file."""
    return posixpath.normpath(path).removeprefix("./")


def read_json(path: Path) -> dict:
    """A JSON file as a dict, or {} when it is missing or invalid. tsconfig.json may contain comments."""
    if not path.is_file() or path.is_symlink():
        return {}

    content = path.read_text(encoding="utf-8", errors="replace")
    without_comments = re.sub(r'"(?:\\.|[^"\\])*"|//[^\n]*|/\*.*?\*/', lambda match: match.group(0) if match.group(0).startswith('"') else "", content, flags=re.DOTALL)
    without_trailing_commas = re.sub(r",(\s*[}\]])", r"\1", without_comments)

    try:
        data = json.loads(without_trailing_commas)
    except ValueError:
        return {}

    return data if isinstance(data, dict) else {}


if __name__ == "__main__":
    sys.exit(main())
