#!/usr/bin/env bash
# tests/run_tests.sh — Запуск всех тестов PreLend
# Использование:
#   ./tests/run_tests.sh           — все тесты
#   ./tests/run_tests.sh php       — только PHP тесты
#   ./tests/run_tests.sh python    — только Python тесты

set -uo pipefail
cd "$(dirname "$0")/.."

PHP_PASS=0; PHP_FAIL=0
PY_PASS=0;  PY_FAIL=0

GREEN='\033[0;32m'; RED='\033[0;31m'; YELLOW='\033[1;33m'; NC='\033[0m'

run_php() {
    echo -e "\n${YELLOW}━━━ PHP Tests ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
    for f in tests/test_*.php; do
        echo -e "\n▶ $f"
        if php "$f"; then
            ((PHP_PASS++))
        else
            ((PHP_FAIL++))
            echo -e "${RED}FAIL: $f${NC}"
        fi
    done
}

run_python() {
    echo -e "\n${YELLOW}━━━ Python Tests ━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
    for f in tests/test_*.py; do
        echo -e "\n▶ $f"
        if python3 -m pytest "$f" -v --tb=short 2>/dev/null || python3 "$f" -v 2>&1; then
            ((PY_PASS++))
        else
            ((PY_FAIL++))
            echo -e "${RED}FAIL: $f${NC}"
        fi
    done
}

MODE="${1:-all}"
[[ "$MODE" == "all" || "$MODE" == "php"    ]] && run_php
[[ "$MODE" == "all" || "$MODE" == "python" ]] && run_python

echo -e "\n${YELLOW}━━━ Summary ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
TOTAL_FAIL=$((PHP_FAIL + PY_FAIL))

[[ $PHP_PASS -gt 0 || $PHP_FAIL -gt 0 ]] && \
    echo -e "PHP:    pass=$PHP_PASS fail=$PHP_FAIL"
[[ $PY_PASS  -gt 0 || $PY_FAIL  -gt 0 ]] && \
    echo -e "Python: pass=$PY_PASS  fail=$PY_FAIL"

if [[ $TOTAL_FAIL -eq 0 ]]; then
    echo -e "\n${GREEN}✅ Все тесты прошли${NC}"
    exit 0
else
    echo -e "\n${RED}❌ Упало тест-файлов: $TOTAL_FAIL${NC}"
    exit 1
fi
