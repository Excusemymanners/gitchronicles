# Analizorul de cod (Python + tree-sitter)
Instalare, o singură dată: `composer analyzer:install` — creează `analyzer/.venv`, instalează `requirements.txt` și descarcă gramaticile tree-sitter (singurul pas care folosește internetul).
Laravel îl pornește din jobul `AnalyzeRepository`; de mână: `printf 'app.php\n' | analyzer/.venv/bin/python analyzer/analyze.py <folder> --files-from -` (JSON pe ecran).
